<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table): void {
                if (!Schema::hasColumn('settings', 'streak_reward_days')) {
                    $table->unsignedSmallInteger('streak_reward_days')->default(7);
                }
                if (!Schema::hasColumn('settings', 'streak_reward_coins')) {
                    $table->unsignedInteger('streak_reward_coins')->default(100);
                }
                if (!Schema::hasColumn('settings', 'streak_reward_rolling_30_day_cap')) {
                    $table->unsignedInteger('streak_reward_rolling_30_day_cap')->default(400);
                }
            });
        }

        if (!Schema::hasTable('user_reward_checkins')) {
            Schema::create('user_reward_checkins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checkin_date');
            $table->timestamps();

            $table->unique(['user_id', 'checkin_date'], 'reward_checkin_user_date_unique');
            $table->index('checkin_date', 'reward_checkin_date_index');
            });
        }

        if (!Schema::hasTable('reward_rules')) {
            Schema::create('reward_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->unsignedInteger('coins_amount');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('rolling_30_day_cap')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'reward_rules_active_order_index');
            });
        }

        $settings = Schema::hasTable('settings') ? DB::table('settings')->first() : null;
        $now = now();
        DB::table('reward_rules')->insertOrIgnore([
            $this->rule('welcome_bonus', 'هدية أول تسجيل', 'Welcome bonus', (int) ($settings->welcome_bonus_coins ?? 20), 1, null, null, 10, $now),
            $this->rule('daily_checkin', 'فتح التطبيق يوميًا', 'Daily check-in', (int) ($settings->daily_reward_coins ?? 15), 1, null, (int) ($settings->daily_reward_rolling_30_day_cap ?? 150), 20, $now),
            $this->rule('streak_milestone', 'اكتمال الاستمرارية', 'Streak milestone', 100, 7, null, 400, 30, $now),
            $this->rule('study_session', 'جلسة دراسة مؤهلة', 'Qualified study session', (int) ($settings->study_reward_coins ?? 10), (int) ($settings->study_reward_minutes ?? 5), (int) ($settings->study_reward_daily_cap ?? 20), (int) ($settings->study_reward_rolling_30_day_cap ?? 200), 40, $now),
            $this->rule('first_project_passed', 'أول مشروع ناجح', 'First passed project', (int) ($settings->first_project_reward_coins ?? 150), 1, null, (int) ($settings->first_project_reward_coins ?? 150), 50, $now),
            $this->rule('course_completed', 'إنهاء كورس', 'Course completion', (int) ($settings->course_completion_reward_coins ?? 200), 1, null, (int) ($settings->course_completion_rolling_30_day_cap ?? 200), 60, $now),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_rules');
        Schema::dropIfExists('user_reward_checkins');

        if (Schema::hasTable('settings')) {
            $columns = array_values(array_filter(
                ['streak_reward_days', 'streak_reward_coins', 'streak_reward_rolling_30_day_cap'],
                static fn (string $column): bool => Schema::hasColumn('settings', $column)
            ));
            if ($columns !== []) {
                Schema::table('settings', fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }

    private function rule(
        string $event,
        string $arabic,
        string $english,
        int $coins,
        int $interval,
        ?int $dailyCap,
        ?int $rollingCap,
        int $order,
        $now
    ): array {
        return [
            'event_key' => $event,
            'title_ar' => $arabic,
            'title_en' => $english,
            'coins_amount' => max(0, $coins),
            'interval_count' => max(1, $interval),
            'daily_cap' => $dailyCap,
            'rolling_30_day_cap' => $rollingCap,
            'is_active' => true,
            'sort_order' => $order,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
