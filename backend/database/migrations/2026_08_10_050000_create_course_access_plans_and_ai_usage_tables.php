<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_access_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->unsignedInteger('price_coins');
            $table->boolean('chat_enabled')->default(false);
            $table->unsignedInteger('chat_message_limit')->default(0);
            $table->unsignedBigInteger('chat_token_budget')->default(0);
            $table->decimal('ai_budget_usd', 12, 6)->default(0);
            $table->decimal('request_reserve_usd', 12, 6)->default(0);
            $table->unsignedBigInteger('project_feedback_token_budget')->default(0);
            $table->decimal('project_feedback_budget_usd', 12, 6)->default(0);
            $table->decimal('project_feedback_reserve_usd', 12, 6)->default(0);
            $table->unsignedInteger('max_output_tokens')->default(320);
            $table->string('model_override')->nullable();
            $table->string('project_feedback_level', 24)->default('pass_only');
            $table->boolean('project_output_enabled')->default(false);
            $table->boolean('certificate_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();

            $table->unique(['course_id', 'code']);
            $table->index(['course_id', 'is_active', 'sort_order']);
        });

        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->foreignId('access_plan_id')
                ->nullable()
                ->after('order_id')
                ->constrained('course_access_plans')
                ->nullOnDelete();
            $table->json('access_plan_snapshot')->nullable();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('access_plan_id')
                ->nullable()
                ->after('course_id')
                ->constrained('course_access_plans')
                ->nullOnDelete();
            $table->json('access_plan_snapshot')->nullable();
        });

        Schema::create('ai_entitlement_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('course_enrollments')->cascadeOnDelete();
            $table->foreignId('access_plan_id')->nullable()->constrained('course_access_plans')->nullOnDelete();
            $table->string('feature', 40);
            $table->unsignedInteger('used_requests')->default(0);
            $table->unsignedInteger('reserved_requests')->default(0);
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->decimal('used_cost_usd', 12, 6)->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['enrollment_id', 'feature']);
        });

        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('enrollment_id')->constrained('course_enrollments')->cascadeOnDelete();
            $table->foreignId('access_plan_id')->nullable()->constrained('course_access_plans')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->string('model')->nullable();
            $table->string('status', 20)->default('reserved');
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('provider_request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'feature', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Existing published courses receive editable defaults. These values
        // are a safe starting point only; finance owns the final coin prices
        // and provider budgets from the dashboard.
        DB::table('courses')->orderBy('id')->chunkById(200, function ($courses): void {
            $now = now();
            $rows = [];
            foreach ($courses as $course) {
                $base = max(0, (int) ($course->price ?? 0));
                $round = static fn (float $value): int => (int) (ceil(max(0, $value) / 50) * 50);
                $coinValue = max(0.000001, (float) config('course_plans.net_usd_per_paid_coin', .001));
                $safety = max(1, (float) config('course_plans.ai_cost_safety_multiplier', 2));
                $costToCoins = static fn (float $usd): int => max(50, $round(($usd * $safety) / $coinValue));
                $guidedPrice = $base + $costToCoins(0.45 + 0.20);
                $mentorPrice = max($base + $costToCoins(1.50 + 0.60), $guidedPrice + 1000);
                $rows[] = [
                    'course_id' => $course->id, 'code' => 'basic',
                    'name_ar' => 'التعلّم', 'name_en' => 'Learning',
                    'price_coins' => $base, 'chat_enabled' => false,
                    'chat_message_limit' => 0, 'chat_token_budget' => 0,
                    'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
                    'project_feedback_token_budget' => 0,
                    'project_feedback_budget_usd' => 0, 'project_feedback_reserve_usd' => 0,
                    'max_output_tokens' => 260, 'model_override' => null,
                    'project_feedback_level' => 'pass_only',
                    'project_output_enabled' => false, 'certificate_enabled' => true,
                    'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now,
                ];
                $rows[] = [
                    'course_id' => $course->id, 'code' => 'guided',
                    'name_ar' => 'التعلّم بإرشاد', 'name_en' => 'Guided learning',
                    'price_coins' => $guidedPrice, 'chat_enabled' => true,
                    'chat_message_limit' => 25, 'chat_token_budget' => 12000,
                    'ai_budget_usd' => 0.45, 'request_reserve_usd' => 0.015,
                    'project_feedback_token_budget' => 6000,
                    'project_feedback_budget_usd' => 0.20, 'project_feedback_reserve_usd' => 0.04,
                    'max_output_tokens' => 320, 'model_override' => null,
                    'project_feedback_level' => 'report',
                    'project_output_enabled' => false, 'certificate_enabled' => true,
                    'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now,
                ];
                $rows[] = [
                    'course_id' => $course->id, 'code' => 'mentor',
                    'name_ar' => 'التعلّم بمتابعة', 'name_en' => 'Supported learning',
                    'price_coins' => $mentorPrice, 'chat_enabled' => true,
                    'chat_message_limit' => 80, 'chat_token_budget' => 42000,
                    'ai_budget_usd' => 1.50, 'request_reserve_usd' => 0.025,
                    'project_feedback_token_budget' => 16000,
                    'project_feedback_budget_usd' => 0.60, 'project_feedback_reserve_usd' => 0.08,
                    'max_output_tokens' => 480, 'model_override' => null,
                    'project_feedback_level' => 'enhanced',
                    'project_output_enabled' => true, 'certificate_enabled' => true,
                    'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if ($rows !== []) DB::table('course_access_plans')->insert($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_entitlement_usages');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('access_plan_snapshot');
            $table->dropConstrainedForeignId('access_plan_id');
        });
        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->dropColumn('access_plan_snapshot');
            $table->dropConstrainedForeignId('access_plan_id');
        });
        Schema::dropIfExists('course_access_plans');
    }
};
