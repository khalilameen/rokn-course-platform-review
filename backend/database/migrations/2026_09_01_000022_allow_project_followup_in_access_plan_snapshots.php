<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** MySQL commits each CHECK replacement implicitly. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->replaceSnapshotChecks(true);
    }

    public function down(): void
    {
        $this->replaceSnapshotChecks(false);
    }

    private function replaceSnapshotChecks(bool $allowVersionThree): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (!$allowVersionThree) {
            foreach (['orders', 'course_enrollments'] as $table) {
                $versionThree = DB::table($table)
                    ->whereRaw("JSON_VALID(access_plan_snapshot) = 1")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(access_plan_snapshot, '$.version')) = '3'")
                    ->exists();
                if ($versionThree) {
                    throw new RuntimeException(
                        "Cannot restore the v2 snapshot constraint while {$table} contains v3 contracts."
                    );
                }
            }
        }

        foreach ([
            ['orders', 'orders_access_plan_snapshot_check', false],
            ['course_enrollments', 'enrollments_access_plan_snapshot_check', true],
        ] as [$table, $constraint, $requiresPlanOrder]) {
            if ($this->checkExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$constraint}`");
            }

            $schema = 'CONVERT(0x'
                . bin2hex($this->snapshotJsonSchema($allowVersionThree))
                . ' USING utf8mb4)';
            $planOrder = $requiresPlanOrder ? 'AND access_plan_order_id IS NOT NULL ' : '';
            $expression = '(access_plan_id IS NULL OR ('
                . 'access_plan_snapshot IS NOT NULL '
                . $planOrder
                . "AND JSON_SCHEMA_VALID({$schema}, access_plan_snapshot) = 1 "
                . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(access_plan_snapshot, '$.plan_id')) "
                . 'AS UNSIGNED) = access_plan_id))';
            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$expression})"
            );
        }
    }

    private function checkExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', DB::getTablePrefix() . $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function snapshotJsonSchema(bool $allowVersionThree): string
    {
        $baseMoneyKeys = [
            'ai_budget_usd',
            'request_reserve_usd',
            'project_feedback_budget_usd',
            'project_feedback_reserve_usd',
        ];
        $v1Money = [];
        $fixedMoney = [];
        foreach ($baseMoneyKeys as $key) {
            $v1Money[$key] = ['type' => 'number', 'minimum' => 0];
            $fixedMoney[$key] = [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ];
        }
        $followupMoney = [
            'project_followup_budget_usd' => [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ],
            'project_followup_reserve_usd' => [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ],
        ];
        $required = [
            'version', 'plan_id', 'code', 'name_ar', 'price_coins',
            'chat_enabled', 'chat_message_limit', 'chat_token_budget',
            'ai_budget_usd', 'request_reserve_usd',
            'project_feedback_token_budget', 'project_feedback_budget_usd',
            'project_feedback_reserve_usd', 'max_output_tokens',
            'model_override', 'project_feedback_level',
            'project_output_enabled', 'certificate_enabled', 'purchased_at',
        ];
        $versions = [1, 2];
        $oneOf = [
            [
                'properties' => array_merge(
                    ['version' => ['type' => 'integer', 'enum' => [1]]],
                    $v1Money
                ),
            ],
            [
                'required' => ['sort_order', 'minimum_paid_coins'],
                'properties' => array_merge([
                    'version' => ['type' => 'integer', 'enum' => [2]],
                    'sort_order' => ['type' => 'integer', 'minimum' => 0],
                    'minimum_paid_coins' => ['type' => 'integer', 'minimum' => 0],
                ], $fixedMoney),
            ],
        ];
        if ($allowVersionThree) {
            $versions[] = 3;
            $oneOf[] = [
                'required' => [
                    'sort_order', 'minimum_paid_coins',
                    'project_followup_message_limit',
                    'project_followup_token_budget',
                    'project_followup_budget_usd',
                    'project_followup_reserve_usd',
                ],
                'properties' => array_merge([
                    'version' => ['type' => 'integer', 'enum' => [3]],
                    'sort_order' => ['type' => 'integer', 'minimum' => 0],
                    'minimum_paid_coins' => ['type' => 'integer', 'minimum' => 0],
                    'project_followup_message_limit' => ['type' => 'integer', 'minimum' => 0],
                    'project_followup_token_budget' => ['type' => 'integer', 'minimum' => 0],
                ], $fixedMoney, $followupMoney),
            ];
        }

        return (string) json_encode([
            'type' => 'object',
            'required' => $required,
            'properties' => [
                'version' => ['type' => 'integer', 'enum' => $versions],
                'plan_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'enum' => ['basic', 'guided', 'mentor']],
                'name_ar' => ['type' => 'string', 'minLength' => 1],
                'price_coins' => ['type' => 'integer', 'minimum' => 0],
                'minimum_paid_coins' => ['type' => 'integer', 'minimum' => 0],
                'chat_enabled' => ['type' => 'boolean'],
                'chat_message_limit' => ['type' => 'integer', 'minimum' => 0],
                'chat_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
                'ai_budget_usd' => ['type' => ['number', 'string']],
                'request_reserve_usd' => ['type' => ['number', 'string']],
                'project_feedback_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'project_feedback_budget_usd' => ['type' => ['number', 'string']],
                'project_feedback_reserve_usd' => ['type' => ['number', 'string']],
                'project_followup_message_limit' => ['type' => 'integer', 'minimum' => 0],
                'project_followup_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'project_followup_budget_usd' => ['type' => ['number', 'string']],
                'project_followup_reserve_usd' => ['type' => ['number', 'string']],
                'max_output_tokens' => ['type' => 'integer', 'minimum' => 1],
                'model_override' => ['type' => ['string', 'null']],
                'project_feedback_level' => [
                    'type' => 'string',
                    'enum' => ['pass_only', 'report', 'enhanced'],
                ],
                'project_output_enabled' => ['type' => 'boolean'],
                'certificate_enabled' => ['type' => 'boolean'],
                'purchased_at' => ['type' => 'string', 'minLength' => 1],
            ],
            'oneOf' => $oneOf,
            'additionalProperties' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
};
