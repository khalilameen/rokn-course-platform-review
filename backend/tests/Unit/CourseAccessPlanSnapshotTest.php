<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CourseAccessPlanSnapshot;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CourseAccessPlanSnapshotTest extends TestCase
{
    public function test_complete_v2_snapshot_is_accepted(): void
    {
        CourseAccessPlanSnapshot::assertValidForPlan(41, $this->snapshot());

        self::assertTrue(true);
    }

    public function test_plan_backed_entitlement_cannot_be_saved_without_snapshot(): void
    {
        $this->expectException(LogicException::class);

        CourseAccessPlanSnapshot::assertValidForPlan(41, null);
    }

    public function test_snapshot_cannot_claim_another_plan(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['plan_id'] = 99;

        $this->expectException(LogicException::class);

        CourseAccessPlanSnapshot::assertValidForPlan(41, $snapshot);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'version' => 2,
            'plan_id' => 41,
            'code' => 'guided',
            'name_ar' => 'التعلّم بإرشاد',
            'price_coins' => 2500,
            'minimum_paid_coins' => 500,
            'sort_order' => 20,
            'chat_enabled' => true,
            'chat_message_limit' => 25,
            'chat_token_budget' => 12000,
            'ai_budget_usd' => '0.450000',
            'request_reserve_usd' => '0.015000',
            'project_feedback_token_budget' => 6000,
            'project_feedback_budget_usd' => '0.200000',
            'project_feedback_reserve_usd' => '0.040000',
            'max_output_tokens' => 320,
            'model_override' => null,
            'project_feedback_level' => 'report',
            'project_output_enabled' => false,
            'certificate_enabled' => true,
            'purchased_at' => '2026-08-10T12:00:00+00:00',
        ];
    }
}
