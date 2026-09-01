<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('course_access_plans')) return;
        if (!Schema::hasColumn('course_access_plans', 'minimum_paid_coins')) {
            Schema::table('course_access_plans', function (Blueprint $table): void {
                $table->unsignedInteger('minimum_paid_coins')->default(0)->after('price_coins');
            });
        }

        // Keep the historical calculation deterministic. Runtime pricing may
        // evolve, but replaying this migration must not rewrite the baseline.
        $coinValue = .001;
        $safety = 2.0;
        DB::table('course_access_plans')
            ->where(function ($query): void {
                $query->where('chat_enabled', true)
                    ->orWhereIn('project_feedback_level', ['report', 'enhanced']);
            })
            ->orderBy('id')
            ->chunkById(200, function ($plans) use ($coinValue, $safety): void {
                foreach ($plans as $plan) {
                    $providerBudget = max(
                        0,
                        (float) $plan->ai_budget_usd
                            + (float) $plan->project_feedback_budget_usd
                    );
                    $costFloor = (int) (ceil((($providerBudget * $safety) / $coinValue) / 50) * 50);
                    DB::table('course_access_plans')->where('id', $plan->id)->update([
                        // Never rewrite a plan price during a schema migration.
                        // An underpriced plan therefore requires its whole price
                        // from purchased coins until finance corrects it.
                        'minimum_paid_coins' => min(
                            max(0, (int) $plan->price_coins),
                            max(0, $costFloor)
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('course_access_plans') && Schema::hasColumn('course_access_plans', 'minimum_paid_coins')) {
            Schema::table('course_access_plans', fn (Blueprint $table) => $table->dropColumn('minimum_paid_coins'));
        }
    }
};
