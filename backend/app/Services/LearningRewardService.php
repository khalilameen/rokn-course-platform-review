<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Project;
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

        return [
            'welcome_bonus_coins' => (int) $settings->welcome_bonus_coins,
            'reward_balance_cap' => (int) $settings->reward_balance_cap,
            'max_reward_contribution_per_course' => (int) $settings->max_reward_contribution_per_course,
            'daily' => [
                'coins' => (int) $settings->daily_reward_coins,
                'rolling_30_day_cap' => (int) $settings->daily_reward_rolling_30_day_cap,
            ],
            'study' => [
                'coins' => (int) $settings->study_reward_coins,
                'qualified_minutes' => (int) $settings->study_reward_minutes,
                'daily_cap' => (int) $settings->study_reward_daily_cap,
                'rolling_30_day_cap' => (int) $settings->study_reward_rolling_30_day_cap,
            ],
            'first_project' => [
                'coins' => (int) $settings->first_project_reward_coins,
                'lifetime_cap' => (int) $settings->first_project_reward_coins,
            ],
            'course_completion' => [
                'coins' => (int) $settings->course_completion_reward_coins,
                'rolling_30_day_cap' => (int) $settings->course_completion_rolling_30_day_cap,
            ],
        ];
    }

    public function claimDaily(User $user): array
    {
        $settings = $this->settings();
        $today = now()->toDateString();
        $transaction = $this->award(
            $user,
            (int) $settings->daily_reward_coins,
            'daily_learning_reward',
            "daily-learning:{$user->id}:{$today}",
            (int) $settings->daily_reward_rolling_30_day_cap,
            null,
            ['activity_date' => $today]
        );

        return $this->result($user, $transaction);
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

        $settings = $this->settings();
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

        $slotSeconds = max(60, (int) $settings->study_reward_minutes * 60);
        $earnedSlots = intdiv((int) $activity->qualified_seconds, $slotSeconds);
        $coinsPerSlot = max(0, (int) $settings->study_reward_coins);
        $dailySlots = $coinsPerSlot > 0
            ? intdiv(max(0, (int) $settings->study_reward_daily_cap), $coinsPerSlot)
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
                (int) $settings->study_reward_rolling_30_day_cap,
                null,
                [
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

        $settings = $this->settings();
        $transaction = $this->award(
            $user,
            (int) $settings->first_project_reward_coins,
            'first_project_reward',
            "first-project-reward:{$user->id}",
            (int) $settings->first_project_reward_coins,
            $project,
            ['project_id' => $project->id]
        );

        return $this->result($user, $transaction);
    }

    public function awardCourseCompletion(User $user, Course $course): array
    {
        if ($this->usesInstitutionalGrant($user, $course)) {
            return $this->result($user, null, ['excluded_for_grant' => true]);
        }

        $settings = $this->settings();
        $transaction = $this->award(
            $user,
            (int) $settings->course_completion_reward_coins,
            'course_completion_reward',
            "course-completion-reward:{$user->id}:{$course->id}",
            (int) $settings->course_completion_rolling_30_day_cap,
            $course,
            ['course_id' => $course->id]
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
