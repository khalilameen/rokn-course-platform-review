<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedInteger('welcome_bonus_coins')->default(20);
            $table->unsignedInteger('reward_balance_cap')->default(1200);
            $table->unsignedInteger('max_reward_contribution_per_course')->default(1200);
            $table->unsignedInteger('daily_reward_coins')->default(15);
            $table->unsignedInteger('daily_reward_rolling_30_day_cap')->default(150);
            $table->unsignedInteger('study_reward_coins')->default(10);
            $table->unsignedSmallInteger('study_reward_minutes')->default(5);
            $table->unsignedInteger('study_reward_daily_cap')->default(20);
            $table->unsignedInteger('study_reward_rolling_30_day_cap')->default(200);
            $table->unsignedInteger('first_project_reward_coins')->default(150);
            $table->unsignedInteger('course_completion_reward_coins')->default(200);
            $table->unsignedInteger('course_completion_rolling_30_day_cap')->default(200);
        });

        Schema::create('user_daily_learning_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->unsignedInteger('qualified_seconds')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'activity_date'], 'daily_learning_user_date_unique');
            $table->index(['activity_date', 'qualified_seconds'], 'daily_learning_date_seconds_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_learning_activities');

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'welcome_bonus_coins',
                'reward_balance_cap',
                'max_reward_contribution_per_course',
                'daily_reward_coins',
                'daily_reward_rolling_30_day_cap',
                'study_reward_coins',
                'study_reward_minutes',
                'study_reward_daily_cap',
                'study_reward_rolling_30_day_cap',
                'first_project_reward_coins',
                'course_completion_reward_coins',
                'course_completion_rolling_30_day_cap',
            ]);
        });
    }
};
