<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Support\CourseAccessPlanSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final readonly class CourseAccessPlanService
{
    /** @return Collection<int, CourseAccessPlan> */
    public function publicPlans(Course $course, bool $lockForUpdate = false): Collection
    {
        if (!Schema::hasTable('course_access_plans')) {
            return collect();
        }

        $query = $course->accessPlans()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function selectedPlan(
        Course $course,
        ?string $code,
        bool $lockForUpdate = false
    ): ?CourseAccessPlan
    {
        $plans = $this->publicPlans($course, $lockForUpdate);
        if ($plans->isEmpty()) {
            if (Schema::hasTable('course_access_plans') && $course->accessPlans()->exists()) {
                throw ValidationException::withMessages([
                    'access_plan_code' => ['فتح هذا الكورس متوقف مؤقتًا حتى تُنشر خطة متاحة.'],
                ]);
            }
            // Pre-migration enrollments retain the single-price contract.
            return null;
        }

        $normalized = strtolower(trim((string) $code));
        if ($normalized === '') {
            // Older clients default to the basic commercial contract.
            return $plans->firstWhere('code', CourseAccessPlan::BASIC) ?: $plans->first();
        }

        $plan = $plans->firstWhere('code', $normalized);
        if (!$plan) {
            throw ValidationException::withMessages([
                'access_plan_code' => ['هذه الخطة لم تعد متاحة. حدّث الصفحة واختر مرة أخرى.'],
            ]);
        }

        return $plan;
    }

    public function planForEnrollment(CourseEnrollment $enrollment): ?CourseAccessPlan
    {
        if (!Schema::hasColumn('course_enrollments', 'access_plan_id')) {
            return null;
        }
        if (!$enrollment->access_plan_id) {
            return null;
        }

        return $enrollment->relationLoaded('accessPlan')
            ? $enrollment->accessPlan
            : $enrollment->accessPlan()->first();
    }

    public function snapshot(CourseAccessPlan $plan, ?CarbonInterface $purchasedAt = null): array
    {
        return [
            'version' => CourseAccessPlanSnapshot::CURRENT_VERSION,
            'plan_id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name_ar' => (string) $plan->name_ar,
            'price_coins' => (int) $plan->price_coins,
            'minimum_paid_coins' => (int) $plan->minimum_paid_coins,
            'sort_order' => (int) $plan->sort_order,
            'chat_enabled' => (bool) $plan->chat_enabled,
            'chat_message_limit' => (int) $plan->chat_message_limit,
            'chat_token_budget' => (int) $plan->chat_token_budget,
            'chat_attachments_enabled' => (bool) $plan->chat_attachments_enabled,
            'chat_attachment_max_files' => (int) $plan->chat_attachment_max_files,
            'project_followup_attachments_enabled' => (bool) $plan->project_followup_attachments_enabled,
            'project_followup_attachment_max_files' => (int) $plan->project_followup_attachment_max_files,
            // Provider budgets remain fixed-decimal receipt values.
            'ai_budget_usd' => $this->formatUsd($plan->ai_budget_usd),
            'request_reserve_usd' => $this->formatUsd($plan->request_reserve_usd),
            'project_feedback_token_budget' => (int) $plan->project_feedback_token_budget,
            'project_feedback_budget_usd' => $this->formatUsd($plan->project_feedback_budget_usd),
            'project_feedback_reserve_usd' => $this->formatUsd($plan->project_feedback_reserve_usd),
            'project_followup_message_limit' => (int) $plan->project_followup_message_limit,
            'project_followup_token_budget' => (int) $plan->project_followup_token_budget,
            'project_followup_budget_usd' => $this->formatUsd($plan->project_followup_budget_usd),
            'project_followup_reserve_usd' => $this->formatUsd($plan->project_followup_reserve_usd),
            'max_output_tokens' => (int) $plan->max_output_tokens,
            'model_override' => $plan->model_override,
            'project_feedback_level' => (string) $plan->project_feedback_level,
            'project_output_enabled' => (bool) $plan->project_output_enabled,
            'certificate_enabled' => (bool) $plan->certificate_enabled,
            'purchased_at' => ($purchasedAt ?: now())->toIso8601String(),
        ];
    }

    public function termsForEnrollment(CourseEnrollment $enrollment): ?array
    {
        $snapshot = is_array($enrollment->access_plan_snapshot)
            ? $enrollment->access_plan_snapshot
            : null;
        if (!$snapshot || !$enrollment->access_plan_id) {
            return null;
        }

        try {
            CourseAccessPlanSnapshot::assertValidForPlan(
                (int) $enrollment->access_plan_id,
                $snapshot
            );
        } catch (\LogicException) {
            // Purchased terms are a receipt. Falling back to the mutable live
            // plan would silently rewrite limits, cost ceilings and benefits
            // for an old order. An incomplete legacy receipt therefore loses
            // variable/tier benefits until it is explicitly repaired.
            return null;
        }

        return $snapshot;
    }

    /** Public value contract; provider names and dollar budgets never leak to the learner. */
    public function publicPayload(CourseAccessPlan $plan): array
    {
        return $this->publicPayloadFromTerms([
            'code' => $plan->code,
            'name_ar' => $plan->name_ar,
            'price_coins' => $plan->price_coins,
            'minimum_paid_coins' => $plan->minimum_paid_coins,
            'chat_enabled' => $plan->chat_enabled,
            'chat_message_limit' => $plan->chat_message_limit,
            'chat_token_budget' => $plan->chat_token_budget,
            'chat_attachments_enabled' => $plan->chat_attachments_enabled,
            'chat_attachment_max_files' => $plan->chat_attachment_max_files,
            'project_followup_attachments_enabled' => $plan->project_followup_attachments_enabled,
            'project_followup_attachment_max_files' => $plan->project_followup_attachment_max_files,
            'ai_budget_usd' => $plan->ai_budget_usd,
            'request_reserve_usd' => $plan->request_reserve_usd,
            'max_output_tokens' => $plan->max_output_tokens,
            'project_feedback_level' => $plan->project_feedback_level,
            'project_feedback_token_budget' => $plan->project_feedback_token_budget,
            'project_feedback_budget_usd' => $plan->project_feedback_budget_usd,
            'project_feedback_reserve_usd' => $plan->project_feedback_reserve_usd,
            'project_followup_message_limit' => $plan->project_followup_message_limit,
            'project_followup_token_budget' => $plan->project_followup_token_budget,
            'project_followup_budget_usd' => $plan->project_followup_budget_usd,
            'project_followup_reserve_usd' => $plan->project_followup_reserve_usd,
            'project_output_enabled' => $plan->project_output_enabled,
            'certificate_enabled' => $plan->certificate_enabled,
        ]);
    }

    /** @param array<string,mixed> $terms */
    public function publicPayloadFromTerms(array $terms): array
    {
        $feedback = (string) ($terms['project_feedback_level'] ?? 'pass_only');
        if (!in_array($feedback, CourseAccessPlan::PROJECT_FEEDBACK_LEVELS, true)) {
            $feedback = CourseAccessPlan::FEEDBACK_PASS_ONLY;
        }

        $maxOutputTokens = max(1, (int) ($terms['max_output_tokens'] ?? 0));
        $chatBudget = max(0, (float) ($terms['ai_budget_usd'] ?? 0));
        $chatReserve = max(0, (float) ($terms['request_reserve_usd'] ?? 0));
        $chatEnabled = (bool) ($terms['chat_enabled'] ?? false)
            && max(0, (int) ($terms['chat_message_limit'] ?? 0)) > 0
            && max(0, (int) ($terms['chat_token_budget'] ?? 0)) >= $maxOutputTokens
            && $chatBudget > 0
            && $chatReserve > 0
            && $chatReserve <= $chatBudget;
        $chatAttachmentsEnabled = $chatEnabled
            && (bool) ($terms['chat_attachments_enabled'] ?? false)
            && max(0, (int) ($terms['chat_attachment_max_files'] ?? 0)) > 0;
        $reportBudget = max(0, (float) ($terms['project_feedback_budget_usd'] ?? 0));
        $reportReserve = max(0, (float) ($terms['project_feedback_reserve_usd'] ?? 0));
        $reportEnabled = in_array($feedback, [
            CourseAccessPlan::FEEDBACK_REPORT,
            CourseAccessPlan::FEEDBACK_ENHANCED,
        ], true)
            && max(0, (int) ($terms['project_feedback_token_budget'] ?? 0)) >= $maxOutputTokens
            && $reportBudget > 0
            && $reportReserve > 0
            && $reportReserve <= $reportBudget;
        $followupBudget = max(0, (float) ($terms['project_followup_budget_usd'] ?? 0));
        $followupReserve = max(0, (float) ($terms['project_followup_reserve_usd'] ?? 0));
        $threadEnabled = $feedback === CourseAccessPlan::FEEDBACK_ENHANCED
            && $reportEnabled
            && max(0, (int) ($terms['project_followup_message_limit'] ?? 0)) > 0
            && max(0, (int) ($terms['project_followup_token_budget'] ?? 0)) >= $maxOutputTokens
            && $followupBudget > 0
            && $followupReserve > 0
            && $followupReserve <= $followupBudget;
        $projectAttachmentsEnabled = $threadEnabled
            && (bool) ($terms['project_followup_attachments_enabled'] ?? false)
            && max(0, (int) ($terms['project_followup_attachment_max_files'] ?? 0)) > 0;
        $effectiveFeedback = !$reportEnabled
            ? CourseAccessPlan::FEEDBACK_PASS_ONLY
            : ($threadEnabled
                ? CourseAccessPlan::FEEDBACK_ENHANCED
                : CourseAccessPlan::FEEDBACK_REPORT);

        return [
            'code' => (string) ($terms['code'] ?? ''),
            'name' => (string) ($terms['name_ar'] ?? ''),
            'price_coins' => max(0, (int) ($terms['price_coins'] ?? 0)),
            'minimum_paid_coins' => max(0, (int) ($terms['minimum_paid_coins'] ?? 0)),
            'chat_enabled' => $chatEnabled,
            'chat_message_limit' => $chatEnabled
                ? max(0, (int) ($terms['chat_message_limit'] ?? 0))
                : 0,
            'chat_attachments_enabled' => $chatAttachmentsEnabled,
            'chat_attachment_max_files' => $chatAttachmentsEnabled
                ? min(5, max(1, (int) ($terms['chat_attachment_max_files'] ?? 1)))
                : 0,
            'project_feedback_level' => $effectiveFeedback,
            'project_report_enabled' => $reportEnabled,
            'project_thread_reply_enabled' => $threadEnabled,
            'project_message_limit' => $threadEnabled
                ? max(0, (int) ($terms['project_followup_message_limit'] ?? 0))
                : 0,
            'project_token_budget' => $threadEnabled
                ? max(0, (int) ($terms['project_followup_token_budget'] ?? 0))
                : 0,
            'project_attachments_enabled' => $projectAttachmentsEnabled,
            'project_attachment_max_files' => $projectAttachmentsEnabled
                ? min(5, max(1, (int) ($terms['project_followup_attachment_max_files'] ?? 1)))
                : 0,
            'project_output_enabled' => $threadEnabled
                && (bool) ($terms['project_output_enabled'] ?? false),
            'certificate_enabled' => (bool) ($terms['certificate_enabled'] ?? false),
        ];
    }

    public function createDefaults(Course $course): void
    {
        if (!Schema::hasTable('course_access_plans')) {
            return;
        }

        DB::transaction(function () use ($course): void {
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            if ($lockedCourse->accessPlans()->exists()) {
                return;
            }

            $base = max(0, (int) ($lockedCourse->price ?? 0));
            $round = static fn (float $value): int => (int) (ceil(max(0, $value) / 50) * 50);
            foreach ($this->defaultDefinitions($base, $round) as $row) {
                $lockedCourse->accessPlans()->create($row);
            }
        }, 3);
    }

    /**
     * Plans shown by the authoring form. A GET must never create commercial
     * contracts behind the editor's back: old courses without plan rows get
     * unsaved defaults, which become real only through the revision-checked
     * course update.
     *
     * @return Collection<int, CourseAccessPlan>
     */
    public function plansForEditor(Course $course): Collection
    {
        if (!Schema::hasTable('course_access_plans')) {
            return collect();
        }

        $plans = $course->accessPlans()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($plans->isNotEmpty()) {
            return $plans;
        }

        $base = max(0, (int) ($course->price ?? 0));
        $round = static fn (float $value): int => (int) (ceil(max(0, $value) / 50) * 50);

        return collect($this->defaultDefinitions($base, $round))
            ->map(function (array $row) use ($course): CourseAccessPlan {
                $plan = new CourseAccessPlan(array_merge($row, [
                    'course_id' => (int) $course->getKey(),
                ]));
                $plan->setRelation('course', $course);

                return $plan;
            });
    }

    /** Plan codes remain stable across dashboard updates. */
    public function syncAdminPlans(Course $course, array $input): void
    {
        if (!Schema::hasTable('course_access_plans')) {
            return;
        }
        $allowedCodes = [CourseAccessPlan::BASIC, CourseAccessPlan::GUIDED, CourseAccessPlan::MENTOR];
        $allowedFeedback = ['pass_only', 'report', 'enhanced'];
        $allowedModels = array_values(array_filter(config('openrouter.allowed_models', [])));
        if (array_diff($allowedCodes, array_keys($input)) !== []) {
            throw ValidationException::withMessages([
                'access_plans' => ['يجب إرسال المستويات الثلاثة كاملة في عملية حفظ واحدة.'],
            ]);
        }

        $prices = collect($allowedCodes)->mapWithKeys(fn (string $code) => [
            $code => max(0, (int) data_get($input, "{$code}.price_coins", 0)),
        ]);
        if ($prices['guided'] < $prices['basic'] || $prices['mentor'] < $prices['guided']) {
            throw ValidationException::withMessages([
                'access_plans' => ['سعر كل مستوى يجب أن يساوي أو يزيد عن المستوى الذي قبله.'],
            ]);
        }

        DB::transaction(function () use (
            $course,
            $input,
            $allowedCodes,
            $allowedFeedback,
            $allowedModels,
            $prices
        ): void {
            // Plan updates and purchases serialize on the course row.
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);
            $lockedCourse->forceFill(['price' => (int) $prices[CourseAccessPlan::BASIC]])->save();

            foreach ($allowedCodes as $position => $code) {
                $row = is_array($input[$code] ?? null) ? $input[$code] : [];
                $model = trim((string) ($row['model_override'] ?? ''));
                if ($model !== '' && !in_array($model, $allowedModels, true)) {
                    throw ValidationException::withMessages([
                        "access_plans.{$code}.model_override" => ['النموذج خارج قائمة الخادم المعتمدة.'],
                    ]);
                }
                $feedback = (string) ($row['project_feedback_level'] ?? 'pass_only');
                if (!in_array($feedback, $allowedFeedback, true)) {
                    $feedback = 'pass_only';
                }
                $chatEnabled = !empty($row['chat_enabled']);
                $chatAttachmentsEnabled = $chatEnabled && !empty($row['chat_attachments_enabled']);
                $projectAttachmentsEnabled = $feedback === 'enhanced'
                    && !empty($row['project_followup_attachments_enabled']);
                $minimumPaidCoins = max(0, (int) ($row['minimum_paid_coins'] ?? 0));
                $chatBudget = max(0, (float) ($row['ai_budget_usd'] ?? 0));
                $chatReserve = max(0, (float) ($row['request_reserve_usd'] ?? 0));
                $projectBudget = max(0, (float) ($row['project_feedback_budget_usd'] ?? 0));
                $projectReserve = max(0, (float) ($row['project_feedback_reserve_usd'] ?? 0));
                $followupMessageLimit = max(0, (int) ($row['project_followup_message_limit'] ?? 0));
                $followupTokenBudget = max(0, (int) ($row['project_followup_token_budget'] ?? 0));
                $followupBudget = max(0, (float) ($row['project_followup_budget_usd'] ?? 0));
                $followupReserve = max(0, (float) ($row['project_followup_reserve_usd'] ?? 0));
                $maxOutputTokens = max(
                    80,
                    min(
                        (int) config('openrouter.max_tokens', 500),
                        (int) ($row['max_output_tokens'] ?? 320)
                    )
                );

                if ($chatEnabled && (
                    (int) ($row['chat_token_budget'] ?? 0) < $maxOutputTokens
                    || $chatBudget <= 0
                    || $chatReserve <= 0
                    || $chatReserve > $chatBudget
                )) {
                    throw ValidationException::withMessages([
                        "access_plans.{$code}" => ['ميزانية المحادثة أو حجز الطلب غير صالحين لهذه الخطة.'],
                    ]);
                }
                $hasVariableCost = $chatEnabled || $feedback !== 'pass_only';
                $priceCoins = max(0, (int) ($row['price_coins'] ?? 0));
                if (
                    $minimumPaidCoins > $priceCoins
                    || ($hasVariableCost && $minimumPaidCoins <= 0)
                ) {
                    throw ValidationException::withMessages([
                        "access_plans.{$code}.minimum_paid_coins" => [
                            'الفئة ذات التكلفة المتغيرة تحتاج حدًا مدفوعًا موجبًا لا يزيد عن سعرها.',
                        ],
                    ]);
                }
                if ($feedback !== 'pass_only' && (
                    (int) ($row['project_feedback_token_budget'] ?? 0) < $maxOutputTokens
                    || $projectBudget <= 0
                    || $projectReserve <= 0
                    || $projectReserve > $projectBudget
                )) {
                    throw ValidationException::withMessages([
                        "access_plans.{$code}" => ['ميزانية تقرير المشروع أو حجزه غير صالحين لهذه الخطة.'],
                    ]);
                }
                if ($feedback === 'enhanced' && (
                    $followupMessageLimit < 1
                    || $followupTokenBudget < $maxOutputTokens
                    || $followupBudget <= 0
                    || $followupReserve <= 0
                    || $followupReserve > $followupBudget
                )) {
                    throw ValidationException::withMessages([
                        "access_plans.{$code}" => ['حدود محادثة تقرير المشروع أو حجزها غير صالحة لهذه الخطة.'],
                    ]);
                }

                $lockedCourse->accessPlans()->updateOrCreate(['code' => $code], [
                    'name_ar' => trim((string) ($row['name_ar'] ?? '')) ?: $this->defaultName($code),
                    'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
                    'price_coins' => max(0, (int) ($row['price_coins'] ?? 0)),
                    'minimum_paid_coins' => $minimumPaidCoins,
                    'chat_enabled' => $chatEnabled,
                    'chat_message_limit' => $chatEnabled ? max(1, (int) ($row['chat_message_limit'] ?? 1)) : 0,
                    'chat_token_budget' => $chatEnabled ? max(100, (int) ($row['chat_token_budget'] ?? 100)) : 0,
                    'chat_attachments_enabled' => $chatAttachmentsEnabled,
                    'chat_attachment_max_files' => $chatAttachmentsEnabled
                        ? min(5, max(1, (int) ($row['chat_attachment_max_files'] ?? 1)))
                        : 0,
                    'project_followup_attachments_enabled' => $projectAttachmentsEnabled,
                    'project_followup_attachment_max_files' => $projectAttachmentsEnabled
                        ? min(5, max(1, (int) ($row['project_followup_attachment_max_files'] ?? 1)))
                        : 0,
                    'ai_budget_usd' => $chatEnabled ? $chatBudget : 0,
                    'request_reserve_usd' => $chatEnabled ? $chatReserve : 0,
                    'project_feedback_token_budget' => $feedback !== 'pass_only'
                        ? max(100, (int) ($row['project_feedback_token_budget'] ?? 100))
                        : 0,
                    'project_feedback_budget_usd' => $feedback !== 'pass_only' ? $projectBudget : 0,
                    'project_feedback_reserve_usd' => $feedback !== 'pass_only' ? $projectReserve : 0,
                    'project_followup_message_limit' => $feedback === 'enhanced' ? $followupMessageLimit : 0,
                    'project_followup_token_budget' => $feedback === 'enhanced' ? $followupTokenBudget : 0,
                    'project_followup_budget_usd' => $feedback === 'enhanced' ? $followupBudget : 0,
                    'project_followup_reserve_usd' => $feedback === 'enhanced' ? $followupReserve : 0,
                    'max_output_tokens' => $maxOutputTokens,
                    'model_override' => $model !== '' ? $model : null,
                    'project_feedback_level' => $feedback,
                    'project_output_enabled' => $feedback === 'enhanced'
                        && !empty($row['project_output_enabled']),
                    'certificate_enabled' => !array_key_exists('certificate_enabled', $row)
                        || !empty($row['certificate_enabled']),
                    'is_active' => !empty($row['is_active']),
                    'sort_order' => ($position + 1) * 10,
                ]);
            }
        }, 3);
    }

    /** Explicit additive grant; never runs as a side effect of editing a live plan. */
    public function grantAttachmentsToCurrentEnrollments(
        Course $course,
        bool $grantCourseChat,
        bool $grantProjectFollowup
    ): int
    {
        if (!$grantCourseChat && !$grantProjectFollowup) return 0;
        $plans = $course->accessPlans()->get()->keyBy('id');
        $updated = 0;
        CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->whereNotNull('access_plan_id')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($enrollments) use (
                $course, $plans, $grantCourseChat, $grantProjectFollowup, &$updated
            ): void {
                foreach ($enrollments as $enrollment) {
                    $snapshot = is_array($enrollment->access_plan_snapshot)
                        ? $enrollment->access_plan_snapshot
                        : null;
                    $plan = $plans->get($enrollment->access_plan_id);
                    if (!$snapshot || (int) ($snapshot['version'] ?? 0) < 3 || !$plan) {
                        continue;
                    }
                    $existingChat = (int) ($snapshot['version'] ?? 0) >= 4
                        && (bool) ($snapshot['chat_attachments_enabled'] ?? false);
                    $existingProject = (int) ($snapshot['version'] ?? 0) >= 5
                        && (bool) ($snapshot['project_followup_attachments_enabled'] ?? false);
                    $chatEnabled = $existingChat || ($grantCourseChat
                        && (bool) ($snapshot['chat_enabled'] ?? false)
                        && (bool) $plan->chat_attachments_enabled
                        && (bool) $course->chat_attachments_enabled);
                    $projectEnabled = $existingProject || ($grantProjectFollowup
                        && (string) ($snapshot['project_feedback_level'] ?? '') === 'enhanced'
                        && (bool) $plan->project_followup_attachments_enabled);
                    if (!$chatEnabled && !$projectEnabled) continue;
                    $snapshot['version'] = CourseAccessPlanSnapshot::CURRENT_VERSION;
                    $snapshot['chat_attachments_enabled'] = $chatEnabled;
                    $snapshot['chat_attachment_max_files'] = $chatEnabled ? min(
                        5, max(1, (int) $course->chat_attachment_max_files),
                        max(1, (int) $plan->chat_attachment_max_files)
                    ) : 0;
                    $snapshot['project_followup_attachments_enabled'] = $projectEnabled;
                    $snapshot['project_followup_attachment_max_files'] = $projectEnabled
                        ? min(5, max(1, (int) $plan->project_followup_attachment_max_files)) : 0;
                    CourseAccessPlanSnapshot::assertValidForPlan((int) $enrollment->access_plan_id, $snapshot);
                    $enrollment->forceFill(['access_plan_snapshot' => $snapshot])->save();
                    $updated++;
                }
            });
          return $updated;
      }

    /** Compatibility for older command callers: explicitly grants both families. */
    public function grantChatAttachmentsToCurrentEnrollments(Course $course): int
    {
        return $this->grantAttachmentsToCurrentEnrollments($course, true, true);
    }

    private function defaultName(string $code): string
    {
        return match ($code) {
            CourseAccessPlan::GUIDED => 'التعلّم بإرشاد',
            CourseAccessPlan::MENTOR => 'التعلّم بمتابعة',
            default => 'التعلّم',
        };
    }

    private function defaultDefinitions(int $base, callable $round): array
    {
        $guidedPrice = $base + $this->costToCoins(.45 + .20, $round);
        $mentorPrice = max(
            $base + $this->costToCoins(1.50 + .60 + .30, $round),
            $guidedPrice + 1000
        );
        return [
            [
                'code' => 'basic',
                'name_ar' => 'التعلّم',
                'name_en' => 'Learning',
                'price_coins' => $base,
                'minimum_paid_coins' => 0,
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_token_budget' => 0,
                'chat_attachments_enabled' => false,
                'chat_attachment_max_files' => 0,
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'ai_budget_usd' => 0,
                'request_reserve_usd' => 0,
                'max_output_tokens' => 260,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0,
                'project_feedback_reserve_usd' => 0,
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0,
                'project_followup_reserve_usd' => 0,
                'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false,
                'certificate_enabled' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'guided',
                'name_ar' => 'التعلّم بإرشاد',
                'name_en' => 'Guided learning',
                'price_coins' => $guidedPrice,
                'minimum_paid_coins' => $this->costToCoins(.45 + .20, $round),
                'chat_enabled' => true,
                'chat_message_limit' => 25,
                'chat_token_budget' => 12000,
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => 2,
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'ai_budget_usd' => .45,
                'request_reserve_usd' => .015,
                'max_output_tokens' => 320,
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => .20,
                'project_feedback_reserve_usd' => .04,
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0,
                'project_followup_reserve_usd' => 0,
                'project_feedback_level' => 'report',
                'project_output_enabled' => false,
                'certificate_enabled' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'mentor',
                'name_ar' => 'التعلّم بمتابعة',
                'name_en' => 'Supported learning',
                'price_coins' => $mentorPrice,
                'minimum_paid_coins' => $this->costToCoins(1.50 + .60 + .30, $round),
                'chat_enabled' => true,
                'chat_message_limit' => 80,
                'chat_token_budget' => 42000,
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => 3,
                'project_followup_attachments_enabled' => true,
                'project_followup_attachment_max_files' => 3,
                'ai_budget_usd' => 1.5,
                'request_reserve_usd' => .025,
                'max_output_tokens' => 480,
                'project_feedback_token_budget' => 16000,
                'project_feedback_budget_usd' => .60,
                'project_feedback_reserve_usd' => .08,
                'project_followup_message_limit' => 20,
                'project_followup_token_budget' => 12000,
                'project_followup_budget_usd' => .30,
                'project_followup_reserve_usd' => .025,
                'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true,
                'certificate_enabled' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];
    }

    private function costToCoins(float $maximumProviderCostUsd, callable $round): int
    {
        $coinValue = max(0.000001, (float) config('course_plans.net_usd_per_paid_coin', .001));
        $safety = max(1, (float) config('course_plans.ai_cost_safety_multiplier', 2));

        return max(50, $round(($maximumProviderCostUsd * $safety) / $coinValue));
    }

    /** @param int|float|string|null $value */
    private function formatUsd($value): string
    {
        return number_format(max(0, (float) $value), 6, '.', '');
    }
}
