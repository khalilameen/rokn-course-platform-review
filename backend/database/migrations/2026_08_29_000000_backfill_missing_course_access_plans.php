<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('courses') || !Schema::hasTable('course_access_plans')) {
            return;
        }

        DB::table('courses')->orderBy('id')->chunkById(200, function ($courses): void {
            $now = now();

            foreach ($courses as $course) {
                $existing = DB::table('course_access_plans')
                    ->where('course_id', $course->id)
                    ->pluck('code')
                    ->all();
                $definitions = $this->definitions(max(0, (int) ($course->price ?? 0)));
                $missing = array_values(array_filter(
                    $definitions,
                    static fn (array $plan): bool => !in_array($plan['code'], $existing, true)
                ));

                if ($missing === []) {
                    continue;
                }

                DB::table('course_access_plans')->insertOrIgnore(array_map(
                    static fn (array $plan): array => [
                        'course_id' => $course->id,
                        ...$plan,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $missing
                ));
            }
        });
    }

    public function down(): void
    {
        // Data repair only. Existing purchases may reference these plans.
    }

    /** @return array<int, array<string, bool|float|int|string|null>> */
    private function definitions(int $base): array
    {
        $round = static fn (float $value): int => (int) (ceil(max(0, $value) / 50) * 50);
        // Historical backfills must replay to the same rows even if runtime
        // pricing configuration changes after this migration ships.
        $coinValue = .001;
        $safety = 2.0;
        $costToCoins = static fn (float $usd): int => max(
            50,
            $round(($usd * $safety) / $coinValue)
        );
        $guidedPrice = $base + $costToCoins(.45 + .20);
        $mentorPrice = max($base + $costToCoins(1.50 + .60), $guidedPrice + 1000);

        return [
            [
                'code' => 'basic', 'name_ar' => 'التعلّم', 'name_en' => 'Learning',
                'price_coins' => $base, 'chat_enabled' => false,
                'chat_message_limit' => 0, 'chat_token_budget' => 0,
                'ai_budget_usd' => 0, 'request_reserve_usd' => 0,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0, 'project_feedback_reserve_usd' => 0,
                'max_output_tokens' => 260, 'model_override' => null,
                'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 10,
            ],
            [
                'code' => 'guided', 'name_ar' => 'التعلّم بإرشاد', 'name_en' => 'Guided learning',
                'price_coins' => $guidedPrice, 'chat_enabled' => true,
                'chat_message_limit' => 25, 'chat_token_budget' => 12000,
                'ai_budget_usd' => .45, 'request_reserve_usd' => .015,
                'project_feedback_token_budget' => 6000,
                'project_feedback_budget_usd' => .20, 'project_feedback_reserve_usd' => .04,
                'max_output_tokens' => 320, 'model_override' => null,
                'project_feedback_level' => 'report',
                'project_output_enabled' => false, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 20,
            ],
            [
                'code' => 'mentor', 'name_ar' => 'التعلّم بمتابعة', 'name_en' => 'Supported learning',
                'price_coins' => $mentorPrice, 'chat_enabled' => true,
                'chat_message_limit' => 80, 'chat_token_budget' => 42000,
                'ai_budget_usd' => 1.50, 'request_reserve_usd' => .025,
                'project_feedback_token_budget' => 16000,
                'project_feedback_budget_usd' => .60, 'project_feedback_reserve_usd' => .08,
                'max_output_tokens' => 480, 'model_override' => null,
                'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true, 'certificate_enabled' => true,
                'is_active' => true, 'sort_order' => 30,
            ],
        ];
    }
};
