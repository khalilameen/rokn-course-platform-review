<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
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
            'version' => 2,
            'plan_id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'name_ar' => (string) $plan->name_ar,
            'price_coins' => (int) $plan->price_coins,
            'sort_order' => (int) $plan->sort_order,
            'chat_enabled' => (bool) $plan->chat_enabled,
            'chat_message_limit' => (int) $plan->chat_message_limit,
            'chat_token_budget' => (int) $plan->chat_token_budget,
            // Provider budgets remain fixed-decimal receipt values.
            'ai_budget_usd' => $this->formatUsd($plan->ai_budget_usd),
            'request_reserve_usd' => $this->formatUsd($plan->request_reserve_usd),
            'project_feedback_token_budget' => (int) $plan->project_feedback_token_budget,
            'project_feedback_budget_usd' => $this->formatUsd($plan->project_feedback_budget_usd),
            'project_feedback_reserve_usd' => $this->formatUsd($plan->project_feedback_reserve_usd),
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
        if ($snapshot && !empty($snapshot['code'])) {
            return $snapshot;
        }
        $plan = $this->planForEnrollment($enrollment);

        return $plan ? $this->snapshot($plan) : null;
    }

    /** Public value contract; provider names and dollar budgets never leak to the learner. */
    public function publicPayload(CourseAccessPlan $plan): array
    {
        return $this->publicPayloadFromTerms([
            'code' => $plan->code,
            'name_ar' => $plan->name_ar,
            'price_coins' => $plan->price_coins,
            'chat_enabled' => $plan->chat_enabled,
            'chat_message_limit' => $plan->chat_message_limit,
            'project_feedback_level' => $plan->project_feedback_level,
            'project_output_enabled' => $plan->project_output_enabled,
            'certificate_enabled' => $plan->certificate_enabled,
        ]);
    }

    /** @param array<string,mixed> $terms */
    public function publicPayloadFromTerms(array $terms): array
    {
        $feedback = (string) ($terms['project_feedback_level'] ?? 'pass_only');

        return [
            'code' => (string) ($terms['code'] ?? ''),
            'name' => (string) ($terms['name_ar'] ?? ''),
            'price_coins' => max(0, (int) ($terms['price_coins'] ?? 0)),
            'chat_enabled' => (bool) ($terms['chat_enabled'] ?? false),
            'chat_message_limit' => max(0, (int) ($terms['chat_message_limit'] ?? 0)),
            'project_feedback_level' => $feedback,
            'project_report_enabled' => in_array($feedback, ['report', 'enhanced'], true),
            'project_output_enabled' => (bool) ($terms['project_output_enabled'] ?? false),
            'certificate_enabled' => (bool) ($terms['certificate_enabled'] ?? true),
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
            $allowedModels
        ): void {
            // Plan updates and purchases serialize on the course row.
            $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);

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
                $chatBudget = max(0, (float) ($row['ai_budget_usd'] ?? 0));
                $chatReserve = max(0, (float) ($row['request_reserve_usd'] ?? 0));
                $projectBudget = max(0, (float) ($row['project_feedback_budget_usd'] ?? 0));
                $projectReserve = max(0, (float) ($row['project_feedback_reserve_usd'] ?? 0));
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

                $lockedCourse->accessPlans()->updateOrCreate(['code' => $code], [
                    'name_ar' => trim((string) ($row['name_ar'] ?? '')) ?: $this->defaultName($code),
                    'name_en' => trim((string) ($row['name_en'] ?? '')) ?: null,
                    'price_coins' => max(0, (int) ($row['price_coins'] ?? 0)),
                    'chat_enabled' => $chatEnabled,
                    'chat_message_limit' => $chatEnabled ? max(1, (int) ($row['chat_message_limit'] ?? 1)) : 0,
                    'chat_token_budget' => $chatEnabled ? max(100, (int) ($row['chat_token_budget'] ?? 100)) : 0,
                    'ai_budget_usd' => $chatEnabled ? $chatBudget : 0,
                    'request_reserve_usd' => $chatEnabled ? $chatReserve : 0,
                    'project_feedback_token_budget' => $feedback !== 'pass_only'
                        ? max(100, (int) ($row['project_feedback_token_budget'] ?? 100))
                        : 0,
                    'project_feedback_budget_usd' => $feedback !== 'pass_only' ? $projectBudget : 0,
                    'project_feedback_reserve_usd' => $feedback !== 'pass_only' ? $projectReserve : 0,
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
            $base + $this->costToCoins(1.50 + .60, $round),
            $guidedPrice + 1000
        );
        return [
            [
                'code' => 'basic',
                'name_ar' => 'التعلّم',
                'name_en' => 'Learning',
                'price_coins' => $base,
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_token_budget' => 0,
                'ai_budget_usd' => 0,
                'request_reserve_usd' => 0,
                'max_output_tokens' => 260,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0,
                'project_feedback_reserve_usd' => 0,
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
                'chat_enabled' => true,
                'chat_message_limit' => 25,
                'chat_token_budget' => 12000,
                'ai_budget_usd' => .45,
                'request_reserve_usd' => .015,
                'max_output_tokens' => 320,
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => .20,
                'project_feedback_reserve_usd' => .04,
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
                'chat_enabled' => true,
                'chat_message_limit' => 80,
                'chat_token_budget' => 42000,
                'ai_budget_usd' => 1.5,
                'request_reserve_usd' => .025,
                'max_output_tokens' => 480,
                'project_feedback_token_budget' => 16000,
                'project_feedback_budget_usd' => .60,
                'project_feedback_reserve_usd' => .08,
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
