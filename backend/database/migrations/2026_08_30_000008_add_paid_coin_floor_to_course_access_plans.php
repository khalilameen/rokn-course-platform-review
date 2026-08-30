<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_access_plans', function (Blueprint $table): void {
            $table->unsignedInteger('minimum_paid_coins')->default(0)->after('price_coins');
        });

        $coinValue = max(0.000001, (float) config('course_plans.net_usd_per_paid_coin', .001));
        $safety = max(1, (float) config('course_plans.ai_cost_safety_multiplier', 2));
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
        Schema::table('course_access_plans', function (Blueprint $table): void {
            $table->dropColumn('minimum_paid_coins');
        });
    }
};
