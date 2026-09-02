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
        return $this->resolveEntitlement($userId, $courseId)['entitlement'];
    }

    /**
     * Resolve the public entitlement and the enrollment that produced it in
     * one pass. Detail resources need both; querying them separately doubled
     * the same course/parent/order/hold reads.
     *
     * @return array{entitlement: array<string,mixed>, enrollment: CourseEnrollment|null}
     */
    public function resolveEntitlement(int $userId, int $courseId): array
    {
        $course = Course::query()->withCount('sections')->find($courseId);
        if (!$course || !$course->isPublishedForLearning()) {
            return ['entitlement' => [
                'has_learning_access' => false,
                'access_type' => 'none',
                'chat_available' => false,
                'certificate_available' => false,
                'plan_code' => null,
                'plan_name' => null,
                'project_feedback_level' => 'pass_only',
            ], 'enrollment' => null];
        }

        // Resolve the candidate enrollment graph once. Previously
        // entitlementFor() loaded it here and hasChatAccess() loaded the same
        // course, parent links, orders and plans again in the same request.
        $candidates = $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get();
        $enrollment = $candidates->first(fn (CourseEnrollment $candidate): bool =>
                !$this->provenance->enrollmentHasActiveHold($candidate, ['course'])
            );
        if (!$enrollment) {
            return ['entitlement' => [
                'has_learning_access' => false,
                'access_type' => 'none',
                'chat_available' => false,
                'certificate_available' => false,
                'plan_code' => null,
                'plan_name' => null,
                'project_feedback_level' => 'pass_only',
            ], 'enrollment' => null];
        }

        $hasChatAccess = (bool) $course->ai_chat_enabled
            && $candidates->contains(
                fn (CourseEnrollment $candidate): bool => $this->enrollmentHasChatAccess($candidate)
            );
        $order = $enrollment->order;
        $planOrder = $enrollment->accessPlanOrder;
        $isPaid = $order
            && $order->isFinanciallyEffective()
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
        $publicTerms = $terms ? $this->plans->publicPayloadFromTerms($terms) : null;
        $hasPlanReference = $enrollment->access_plan_id !== null;

        return ['entitlement' => [
            'has_learning_access' => true,
            'access_type' => ($isPaid || $isPaidPlanUpgrade)
                ? 'paid'
                : ($isGrant ? 'scholarship' : ($isFullAccessCode ? 'course_code' : 'free')),
            'chat_available' => $hasChatAccess,
            'certificate_available' => (!$isGrant || $isPaidPlanUpgrade)
                && ($terms
                    ? (bool) ($terms['certificate_enabled'] ?? false)
                    : !$hasPlanReference),
            'plan_code' => $terms['code'] ?? $plan?->code,
            'plan_name' => $terms['name_ar'] ?? $plan?->name_ar,
            'chat_message_limit' => $terms ? (int) ($terms['chat_message_limit'] ?? 0) : null,
            'project_feedback_level' => $publicTerms['project_feedback_level'] ?? 'pass_only',
        ], 'enrollment' => $enrollment];
    }

    public function hasCertificateAccess(int $userId, int $courseId): bool
    {
        return $this->entitlementFor($userId, $courseId)['certificate_available'];
    }

    /**
     * Resolve the certificate term from the immutable enrollment contract.
     * Unlike the catalogue-facing entitlement resolver, this remains valid
     * while a completed course is temporarily a draft for its next revision.
     */
    public function enrollmentHasCertificateAccess(CourseEnrollment $enrollment): bool
    {
        $relations = ['order.courseCode'];
        if (Schema::hasColumn('course_enrollments', 'access_plan_order_id')) {
            $relations[] = 'accessPlanOrder';
        }
        if (Schema::hasTable('course_access_plans')) {
            $relations[] = 'accessPlan';
        }
        $enrollment->loadMissing($relations);

        if ($this->provenance->enrollmentHasActiveHold($enrollment, ['course'])) {
            return false;
        }

        $order = $enrollment->order;
        $planOrder = $enrollment->accessPlanOrder;
        $isCourseCode = $order && $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE;
        $isGrant = $isCourseCode
            && (!$order->courseCode || $order->courseCode->isInstitutionalGrant());
        $isPaidPlanUpgrade = $this->isPaidPlanUpgrade($enrollment, $planOrder);
        $terms = $this->plans->termsForEnrollment($enrollment);

        return (!$isGrant || $isPaidPlanUpgrade)
            && ($terms
                ? (bool) ($terms['certificate_enabled'] ?? false)
                : $enrollment->access_plan_id === null);
    }

    public function hasLearningAccess(int $userId, int $courseId): bool
    {
        if (!$this->courseIsReady($courseId)) {
            return false;
        }

        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->get()
            ->contains(fn (CourseEnrollment $enrollment): bool =>
                !$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])
            );
    }

    public function hasChatAccess(int $userId, int $courseId): bool
    {
        $course = Course::query()->withCount('sections')->find($courseId);
        if (!$course || !$course->isPublishedForLearning() || !(bool) $course->ai_chat_enabled) {
            return false;
        }

        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get()
            ->contains(fn (CourseEnrollment $enrollment): bool =>
                $this->enrollmentHasChatAccess($enrollment)
            );
    }

    public function activeEnrollmentFor(int $userId, int $courseId): ?CourseEnrollment
    {
        if (!$this->courseIsReady($courseId)) {
            return null;
        }

        return $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->with($this->enrollmentRelations())
            ->get()
            ->first(fn (CourseEnrollment $candidate): bool =>
                !$this->provenance->enrollmentHasActiveHold($candidate, ['course'])
            );
    }

    /**
     * Re-check a captured enrollment without coupling delayed paid work to the
     * course's current catalogue state. Drafting the next curriculum revision
     * must not cancel an already-submitted report, while revocation, expiry,
     * downgrade and financial holds remain authoritative.
     */
    public function activeCapturedEnrollmentFor(
        int $userId,
        int $courseId,
        int $enrollmentId
    ): ?CourseEnrollment {
        $enrollment = $this->activeEnrollments($userId, $this->accessCourseIds($courseId))
            ->whereKey($enrollmentId)
            ->with($this->enrollmentRelations())
            ->first();

        if (!$enrollment || $this->provenance->enrollmentHasActiveHold($enrollment, ['course'])) {
            return null;
        }

        return $enrollment;
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

    private function courseIsReady(int $courseId): bool
    {
        $course = Course::query()->withCount('sections')->find($courseId);

        return $course !== null && $course->isPublishedForLearning();
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
            && $planOrder->isFinanciallyEffective()
            && $planOrder->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
            && (int) $planOrder->user_id === (int) $enrollment->user_id
            && (int) $planOrder->course_id === (int) $enrollment->course_id;
    }

    private function enrollmentHasChatAccess(CourseEnrollment $enrollment): bool
    {
        $order = $enrollment->order;
        if (!$order || !$order->isFinanciallyEffective()) {
            return false;
        }
        $planOrder = $enrollment->accessPlanOrder;
        $paid = $order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
            && ((int) $order->total_coins > 0 || (float) $order->final_amount > 0);
        $fullAccessCode = $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
            && $order->courseCode
            && !$order->courseCode->isInstitutionalGrant();
        $paidPlanUpgrade = $this->isPaidPlanUpgrade($enrollment, $planOrder);
        $terms = $this->plans->termsForEnrollment($enrollment);

        return ($paid || $fullAccessCode || $paidPlanUpgrade)
            && $terms !== null
            && (bool) ($terms['chat_enabled'] ?? false)
            && !$this->provenance->enrollmentHasActiveHold(
                $enrollment,
                ['course', 'chat', 'plan']
            )
            && $this->financialRisk->allowsVariableCostFeatures($enrollment);
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
