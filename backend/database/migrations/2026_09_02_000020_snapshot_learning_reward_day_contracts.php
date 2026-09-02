<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('user_reward_checkins')) {
            $daily = !Schema::hasColumn('user_reward_checkins', 'daily_rule_snapshot');
            $streak = !Schema::hasColumn('user_reward_checkins', 'streak_rule_snapshot');
            $snapshottedAt = !Schema::hasColumn('user_reward_checkins', 'rules_snapshotted_at');
            if ($daily || $streak || $snapshottedAt) {
                Schema::table('user_reward_checkins', function (Blueprint $table) use (
                    $daily,
                    $streak,
                    $snapshottedAt
                ): void {
                    if ($daily) $table->json('daily_rule_snapshot')->nullable()->after('checkin_date');
                    if ($streak) $table->json('streak_rule_snapshot')->nullable()->after('daily_rule_snapshot');
                    if ($snapshottedAt) $table->timestamp('rules_snapshotted_at')->nullable()->after('streak_rule_snapshot');
                });
            }
        }

        if (
            Schema::hasTable('user_daily_learning_activities')
            && !Schema::hasColumn('user_daily_learning_activities', 'reward_contract')
        ) {
            Schema::table('user_daily_learning_activities', function (Blueprint $table): void {
                $table->json('reward_contract')->nullable()->after('qualified_seconds');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('user_daily_learning_activities')
            && Schema::hasColumn('user_daily_learning_activities', 'reward_contract')
        ) {
            Schema::table('user_daily_learning_activities', function (Blueprint $table): void {
                $table->dropColumn('reward_contract');
            });
        }
        if (Schema::hasTable('user_reward_checkins')) {
            $columns = array_values(array_filter([
                'daily_rule_snapshot',
                'streak_rule_snapshot',
                'rules_snapshotted_at',
            ], fn (string $column): bool => Schema::hasColumn('user_reward_checkins', $column)));
            if ($columns !== []) {
                Schema::table('user_reward_checkins', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
