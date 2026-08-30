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
            $table->decimal('openrouter_usd_to_egp_rate', 12, 4)->nullable();
        });
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable()->after('cost_usd');
            $table->decimal('cost_egp', 14, 6)->nullable()->after('fx_rate_to_egp');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_dashboard_login_at')->nullable();
        });

        Schema::create('operating_cost_pools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('service_key', 32);
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3)->default('EGP');
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable();
            $table->string('allocation_driver', 32);
            $table->boolean('is_final')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['period_start', 'period_end', 'service_key'], 'cost_pool_period_service');
            $table->index(['course_id', 'period_start'], 'cost_pool_course_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_cost_pools');
        Schema::table('ai_usage_events', function (Blueprint $table): void {
            $table->dropColumn(['fx_rate_to_egp', 'cost_egp']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_dashboard_login_at');
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('openrouter_usd_to_egp_rate');
        });
    }
};
