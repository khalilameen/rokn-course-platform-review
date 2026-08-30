<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class CourseChatAccessService
{
    public function __construct(
        private FinancialProvenanceService $provenance,
        private CourseAccessPlanService $plans,
        private FinancialAnomalyService $financialRisk
    )
    {
    }

    /**
     * Return the single entitlement contract consumed by course resources and
     * the chat endpoint.  A direct row in course_enrollments is not sufficient
     * to call an enrolment "paid": course codes and institutional grants create
     * the same row shape, but intentionally exclude the variable-cost AI chat.
     *
     * @return array{has_learning_access: bool, access_type: string, chat_available: bool, certificate_available: bool}
     */
    public function entitlementFor(int $userId, int $courseId): array
    {
        $enrollment = $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get()
            ->first(fn (CourseEnrollment $candidate): bool =>
                !$this->provenance->enrollmentHasActiveHold($candidate, ['course'])
            );
        if (!$enrollment) {
            return [
                'has_learning_access' => false,
                'access_type' => 'none',
                'chat_available' => false,
                'certificate_available' => false,
                'plan_code' => null,
                'plan_name' => null,
                'project_feedback_level' => 'pass_only',
            ];
        }

        $hasChatAccess = $this->hasChatAccess($userId, $courseId);
        $order = $enrollment->order;
        $planOrder = $enrollment->accessPlanOrder;
        $isPaid = $order
            && $order->status === Order::STATUS_APPROVED
            && $order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
            && ((int) $order->total_coins > 0 || (float) $order->final_amount > 0);
        $isCourseCode = $order && $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE;
        // A legacy/broken order without a resolvable code must fail closed. It
        // may keep learning access, but it must never silently unlock variable-
        // cost AI or certificates as if it were a paid full-access promotion.
        $isGrant = $isCourseCode
            && (!$order->courseCode || $order->courseCode->isInstitutionalGrant());
        $isFullAccessCode = $isCourseCode && $order->courseCode && !$isGrant;
        $isPaidPlanUpgrade = $this->isPaidPlanUpgrade($enrollment, $planOrder);
        $plan = $this->plans->planForEnrollment($enrollment);
        $terms = $this->plans->termsForEnrollment($enrollment);

        return [
            'has_learning_access' => true,
            'access_type' => ($isPaid || $isPaidPlanUpgrade)
                ? 'paid'
                : ($isGrant ? 'scholarship' : ($isFullAccessCode ? 'course_code' : 'free')),
            'chat_available' => $hasChatAccess,
            'certificate_available' => (!$isGrant || $isPaidPlanUpgrade)
                && (!$terms || (bool) ($terms['certificate_enabled'] ?? true)),
            'plan_code' => $terms['code'] ?? $plan?->code,
            'plan_name' => $terms['name_ar'] ?? $plan?->name_ar,
            'chat_message_limit' => $terms ? (int) ($terms['chat_message_limit'] ?? 0) : null,
            'project_feedback_level' => $terms['project_feedback_level'] ?? 'pass_only',
        ];
    }

    public function hasCertificateAccess(int $userId, int $courseId): bool
    {
        return $this->entitlementFor($userId, $courseId)['certificate_available'];
    }

    public function hasLearningAccess(int $userId, int $courseId): bool
    {
        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->get()
            ->contains(fn (CourseEnrollment $enrollment): bool =>
                !$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])
            );
    }

    public function hasChatAccess(int $userId, int $courseId): bool
    {
        if (!Course::query()->whereKey($courseId)->where('ai_chat_enabled', true)->exists()) {
            return false;
        }

        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get()
            ->contains(function (CourseEnrollment $enrollment): bool {
                $order = $enrollment->order;
                if (!$order || $order->status !== Order::STATUS_APPROVED) return false;
                $planOrder = $enrollment->accessPlanOrder;

                $paid = $order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
                    && ((int) $order->total_coins > 0 || (float) $order->final_amount > 0);
                $fullAccessCode = $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
                    && $order->courseCode
                    && !$order->courseCode->isInstitutionalGrant();
                $paidPlanUpgrade = $this->isPaidPlanUpgrade($enrollment, $planOrder);

                $terms = $this->plans->termsForEnrollment($enrollment);

                return ($paid || $fullAccessCode || $paidPlanUpgrade)
                    && (!$terms || (bool) ($terms['chat_enabled'] ?? false))
                    && !$this->provenance->enrollmentHasActiveHold(
                        $enrollment,
                        ['course', 'chat', 'plan']
                    )
                    && $this->financialRisk->allowsVariableCostFeatures($enrollment);
            });
    }

    public function activeEnrollmentFor(int $userId, int $courseId): ?CourseEnrollment
    {
        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get()
            ->first(fn (CourseEnrollment $candidate): bool =>
                !$this->provenance->enrollmentHasActiveHold($candidate, ['course'])
            );
    }

    private function activeEnrollments(int $userId, array $courseIds): Builder
    {
        return CourseEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /** @return list<int> */
    private function accessCourseIds(int $courseId): array
    {
        $parentIds = CourseSection::query()
            ->where('sectionable_type', Course::class)
            ->where('sectionable_id', $courseId)
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([$courseId, ...$parentIds]));
    }

    private function isPaidPlanUpgrade(
        CourseEnrollment $enrollment,
        ?Order $planOrder
    ): bool {
        return $planOrder !== null
            && (int) $planOrder->id !== (int) $enrollment->order_id
            && $planOrder->parent_order_id !== null
            && $planOrder->status === Order::STATUS_APPROVED
            && $planOrder->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
            && (int) $planOrder->user_id === (int) $enrollment->user_id
            && (int) $planOrder->course_id === (int) $enrollment->course_id;
    }

    /** @return list<string> */
    private function enrollmentRelations(): array
    {
        $relations = ['order.courseCode'];
        if (Schema::hasColumn('course_enrollments', 'access_plan_order_id')) {
            $relations[] = 'accessPlanOrder';
        }
        if (Schema::hasTable('course_access_plans')) {
            $relations[] = 'accessPlan';
        }

        return $relations;
    }
}
