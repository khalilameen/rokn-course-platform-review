<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Project;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserDailyLearningActivity;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

final class LearningRewardService
{
    public function __construct(
        private readonly WalletService $wallet
    ) {
    }

    public function configuration(): array
    {
        $settings = $this->settings();
        $welcome = RewardRule::activeFor('welcome_bonus');
        $daily = RewardRule::activeFor('daily_checkin');
        $streak = RewardRule::activeFor('streak_milestone');
        $study = RewardRule::activeFor('study_session');
        $firstProject = RewardRule::activeFor('first_project_passed');
        $courseCompletion = RewardRule::activeFor('course_completed');

        return [
            'welcome_bonus_coins' => (int) ($welcome?->coins_amount ?? 0),
            'reward_balance_cap' => (int) $settings->reward_balance_cap,
            'max_reward_contribution_per_course' => (int) $settings->max_reward_contribution_per_course,
            'daily' => [
                'enabled' => $daily !== null,
                'coins' => (int) ($daily?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($daily?->rolling_30_day_cap ?? 0),
            ],
            'streak' => [
                'enabled' => $streak !== null,
                'days' => (int) ($streak?->interval_count ?? 0),
                'coins' => (int) ($streak?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($streak?->rolling_30_day_cap ?? 0),
            ],
            'study' => [
                'enabled' => $study !== null,
                'coins' => (int) ($study?->coins_amount ?? 0),
                'qualified_minutes' => (int) ($study?->interval_count ?? 0),
                'daily_cap' => (int) ($study?->daily_cap ?? 0),
                'rolling_30_day_cap' => (int) ($study?->rolling_30_day_cap ?? 0),
            ],
            'first_project' => [
                'enabled' => $firstProject !== null,
                'coins' => (int) ($firstProject?->coins_amount ?? 0),
                'lifetime_cap' => (int) ($firstProject?->rolling_30_day_cap ?? 0),
            ],
            'course_completion' => [
                'enabled' => $courseCompletion !== null,
                'coins' => (int) ($courseCompletion?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($courseCompletion?->rolling_30_day_cap ?? 0),
            ],
        ];
    }

    public function claimDaily(User $user): array
    {
        $dailyRule = RewardRule::activeFor('daily_checkin');
        $streakRule = RewardRule::activeFor('streak_milestone');
        $today = now()->toDateString();
        DB::table('user_reward_checkins')->insertOrIgnore([
            'user_id' => $user->id,
            'checkin_date' => $today,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transaction = $dailyRule ? $this->award(
            $user,
            (int) $dailyRule->coins_amount,
            'daily_learning_reward',
            "daily-learning:{$user->id}:{$today}",
            (int) ($dailyRule->rolling_30_day_cap ?? $dailyRule->coins_amount),
            $dailyRule,
            ['activity_date' => $today, 'reward_rule_id' => $dailyRule->id]
        ) : null;

        $streakDays = $this->currentCheckinStreak((int) $user->id);
        $milestoneDays = max(2, (int) ($streakRule?->interval_count ?? 7));
        $streakTransaction = null;
        if ($streakRule && $streakDays > 0 && $streakDays % $milestoneDays === 0) {
            $streakTransaction = $this->award(
                $user,
                (int) $streakRule->coins_amount,
                'streak_reward',
                "streak-reward:{$user->id}:{$today}",
                (int) ($streakRule->rolling_30_day_cap ?? $streakRule->coins_amount),
                $streakRule,
                [
                    'reward_rule_id' => $streakRule->id,
                    'milestone_days' => $streakDays,
                    'configured_interval_days' => $milestoneDays,
                ]
            );
        }

        return $this->result($user, $transaction, [
            'current_streak_days' => $streakDays,
            'next_streak_reward_at' => $milestoneDays - ($streakDays % $milestoneDays),
            'streak_awarded' => $streakTransaction ? (int) $streakTransaction->amount : 0,
        ]);
    }

    /**
     * Credit only the newly qualified foreground watch time supplied by the
     * server-side watch-history endpoint. The daily and rolling caps make the
     * cost bounded even when a client retries aggressively.
     */
    public function recordStudy(User $user, int $qualifiedSeconds): array
    {
        $seconds = max(0, min(120, $qualifiedSeconds));
        if ($seconds === 0) {
            return $this->result($user, null);
        }

        $rule = RewardRule::activeFor('study_session');
        if (!$rule) {
            return $this->result($user, null);
        }
        $today = now()->toDateString();
        $activity = DB::transaction(function () use ($user, $today, $seconds) {
            // One atomic insert protects the daily row from concurrent player
            // heartbeats. The previous select-then-create sequence could race
            // and violate the (user, day) unique key under normal playback.
            DB::table('user_daily_learning_activities')->insertOrIgnore([
                'user_id' => $user->id,
                'activity_date' => $today,
                'qualified_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $activity = UserDailyLearningActivity::query()
                ->where('user_id', $user->id)
                ->whereDate('activity_date', $today)
                ->lockForUpdate()
                ->firstOrFail();
            $activity->increment('qualified_seconds', $seconds);

            return $activity->fresh();
        });

        $slotSeconds = max(60, (int) $rule->interval_count * 60);
        $earnedSlots = intdiv((int) $activity->qualified_seconds, $slotSeconds);
        $coinsPerSlot = max(0, (int) $rule->coins_amount);
        $dailySlots = $coinsPerSlot > 0
            ? intdiv(max(0, (int) ($rule->daily_cap ?? $coinsPerSlot)), $coinsPerSlot)
            : 0;
        $targetSlots = min($earnedSlots, $dailySlots);
        $creditedSlots = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('category', 'study_reward')
            ->whereDate('occurred_at', $today)
            ->count();

        $last = null;
        for ($sequence = $creditedSlots + 1; $sequence <= $targetSlots; $sequence++) {
            $last = $this->award(
                $user,
                $coinsPerSlot,
                'study_reward',
                "study-reward:{$user->id}:{$today}:{$sequence}",
                (int) ($rule->rolling_30_day_cap ?? $coinsPerSlot),
                $rule,
                [
                    'reward_rule_id' => $rule->id,
                    'activity_date' => $today,
                    'qualified_seconds' => (int) $activity->qualified_seconds,
                    'sequence' => $sequence,
                ]
            );
        }

        return $this->result($user, $last, [
            'qualified_seconds_today' => (int) $activity->qualified_seconds,
            'rewarded_slots_today' => max($creditedSlots, $targetSlots),
        ]);
    }

    public function awardFirstProject(User $user, Project $project): array
    {
        $course = $project->course;
        if ($course && $this->usesInstitutionalGrant($user, $course)) {
            return $this->result($user, null, ['excluded_for_grant' => true]);
        }

        $rule = RewardRule::activeFor('first_project_passed');
        if (!$rule) {
            return $this->result($user, null);
        }
        $transaction = $this->award(
            $user,
            (int) $rule->coins_amount,
            'first_project_reward',
            "first-project-reward:{$user->id}",
            (int) ($rule->rolling_30_day_cap ?? $rule->coins_amount),
            $rule,
            ['project_id' => $project->id, 'reward_rule_id' => $rule->id]
        );

        return $this->result($user, $transaction);
    }

    public function awardCourseCompletion(User $user, Course $course): array
    {
        if ($this->usesInstitutionalGrant($user, $course)) {
            return $this->result($user, null, ['excluded_for_grant' => true]);
        }

        $rule = RewardRule::activeFor('course_completed');
        if (!$rule) {
            return $this->result($user, null);
        }
        $transaction = $this->award(
            $user,
            (int) $rule->coins_amount,
            'course_completion_reward',
            "course-completion-reward:{$user->id}:{$course->id}",
            (int) ($rule->rolling_30_day_cap ?? $rule->coins_amount),
            $rule,
            ['course_id' => $course->id, 'reward_rule_id' => $rule->id]
        );

        return $this->result($user, $transaction);
    }

    private function award(
        User $user,
        int $requested,
        string $category,
        string $idempotencyKey,
        int $rollingCap,
        $source = null,
        array $metadata = []
    ): ?WalletTransaction {
        $requested = max(0, $requested);
        if ($requested === 0) {
            return null;
        }

        $existing = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return null;
        }

        $settings = $this->settings();
        $rollingTotal = (int) WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('category', $category)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->sum('amount');
        $rollingRoom = max(0, $rollingCap - $rollingTotal);
        $freshUser = $user->fresh();
        $balanceRoom = max(
            0,
            (int) $settings->reward_balance_cap - (int) $freshUser->wallet_reward_coins
        );
        $amount = min($requested, $rollingRoom, $balanceRoom);
        if ($amount <= 0) {
            return null;
        }

        return $this->wallet->credit(
            $user->id,
            $amount,
            $category,
            $idempotencyKey,
            $source,
            $metadata + [
                'requested_amount' => $requested,
                'reward_balance_cap' => (int) $settings->reward_balance_cap,
                'rolling_30_day_cap' => $rollingCap,
            ],
            WalletTransaction::BUCKET_REWARD
        );
    }

    private function result(User $user, ?WalletTransaction $transaction, array $extra = []): array
    {
        $fresh = $user->fresh();

        return $extra + [
            'awarded' => $transaction ? (int) $transaction->amount : 0,
            'balance' => (int) $fresh->wallet_coins,
            'reward_balance' => (int) $fresh->wallet_reward_coins,
            'transaction_id' => $transaction?->public_id,
        ];
    }

    private function settings(): Setting
    {
        return Setting::query()->firstOrCreate([]);
    }

    private function currentCheckinStreak(int $userId): int
    {
        $dates = DB::table('user_reward_checkins')
            ->where('user_id', $userId)
            ->where('checkin_date', '>=', now()->subDays(365)->toDateString())
            ->orderByDesc('checkin_date')
            ->pluck('checkin_date')
            ->map(fn ($date): string => (string) $date)
            ->all();

        $expected = now()->startOfDay();
        $streak = 0;
        foreach ($dates as $date) {
            if ($date !== $expected->toDateString()) {
                break;
            }
            $streak++;
            $expected->subDay();
        }

        return $streak;
    }

    private function usesInstitutionalGrant(User $user, Course $course): bool
    {
        return CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('is_active', true)
            ->with('order.courseCode')
            ->get()
            ->contains(function (CourseEnrollment $enrollment): bool {
                $order = $enrollment->order;
                return $order
                    && $order->status === Order::STATUS_APPROVED
                    && $order->payment_method === Order::PAYMENT_METHOD_COURSE_CODE
                    && (bool) $order->courseCode?->isInstitutionalGrant();
            });
    }
}
