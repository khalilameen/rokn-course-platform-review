<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MySQL DDL commits implicitly; expansion steps are retry-safe and guarded. */
    public $withinTransaction = false;

    private const INDEXES = [
        'course_access_plans' => [
            'course_access_plans_course_sort_unique' => ['course_id', 'sort_order'],
            'course_access_plans_id_course_unique' => ['id', 'course_id'],
        ],
        'orders' => [
            'orders_id_user_course_unique' => ['id', 'user_id', 'course_id'],
            'orders_id_user_course_plan_unique' => ['id', 'user_id', 'course_id', 'access_plan_id'],
            'orders_parent_user_course_identity' => ['parent_order_id', 'user_id', 'course_id'],
            'orders_plan_course_identity' => ['access_plan_id', 'course_id'],
            'orders_course_plan_sales_rollup' => ['course_id', 'status', 'access_plan_id'],
        ],
        'course_enrollments' => [
            'enrollments_order_user_course_identity' => ['order_id', 'user_id', 'course_id'],
            'enrollments_plan_order_contract_identity' => [
                'access_plan_order_id', 'user_id', 'course_id', 'access_plan_id',
            ],
            'enrollments_plan_course_identity' => ['access_plan_id', 'course_id'],
            'enrollments_active_course_lookup' => ['course_id', 'is_active', 'expires_at', 'id'],
        ],
        'ai_usage_events' => [
            'ai_events_plan_course_identity' => ['access_plan_id', 'course_id'],
            'ai_events_course_plan_cost_rollup' => ['course_id', 'status', 'access_plan_id', 'feature'],
            'ai_events_reservation_expiry' => ['status', 'reservation_expires_at', 'id'],
        ],
    ];

    private const UNIQUE_INDEXES = [
        'course_access_plans_course_sort_unique',
        'course_access_plans_id_course_unique',
        'orders_id_user_course_unique',
        'orders_id_user_course_plan_unique',
    ];

    private const CHECKS = [
        'course_access_plans_contract_check',
        'orders_access_plan_snapshot_check',
        'enrollments_access_plan_snapshot_check',
        'ai_entitlement_usages_feature_check',
        'ai_usage_events_state_check',
        'orders_parent_precedes_check',
    ];

    public function up(): void
    {
        $this->assertRequiredSchema();
        $this->addLineageColumnsIfMissing();
        $this->backfillOrderLineage();
        $this->assertExistingDataCanBeConstrained();

        if (!Schema::hasColumn('ai_usage_events', 'reservation_expires_at')) {
            Schema::table('ai_usage_events', function (Blueprint $table): void {
                // Nullable is intentional for an expand/deploy/contract rollout:
                // old workers may finish reservations while new code starts
                // writing expiries. The reaper only claims non-null expiries.
                $table->timestamp('reservation_expires_at')->nullable()->after('status');
            });
        }

        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $indexName => $columns) {
                $this->addIndexIfMissing(
                    $tableName,
                    $columns,
                    $indexName,
                    in_array($indexName, self::UNIQUE_INDEXES, true)
                );
            }
        }

        if (!$this->isMySql()) {
            // SQLite is used only by the test suite. Its Laravel 9 grammar
            // cannot add foreign keys or CHECK constraints to existing tables.
            // Production MySQL receives the complete database invariants below.
            return;
        }

        // MySQL rejects CHECK constraints that reference a column whose
        // foreign key uses SET NULL. The composite identity constraints below
        // supersede these legacy single-column keys and deliberately restrict
        // deletion of plans that are preserved in financial snapshots.
        $this->dropForeignKeyIfPresent('orders', 'orders_access_plan_id_foreign');
        $this->dropForeignKeyIfPresent(
            'course_enrollments',
            'course_enrollments_access_plan_id_foreign'
        );

        $this->addForeignKeyIfMissing(
            'orders',
            'orders_parent_order_identity_foreign',
            ['parent_order_id', 'user_id', 'course_id'],
            'orders',
            ['id', 'user_id', 'course_id']
        );
        $this->addForeignKeyIfMissing(
            'course_enrollments',
            'course_enrollments_order_identity_foreign',
            ['order_id', 'user_id', 'course_id'],
            'orders',
            ['id', 'user_id', 'course_id']
        );
        $this->addForeignKeyIfMissing(
            'course_enrollments',
            'enrollments_plan_order_identity_foreign',
            ['access_plan_order_id', 'user_id', 'course_id', 'access_plan_id'],
            'orders',
            ['id', 'user_id', 'course_id', 'access_plan_id']
        );
        $this->addForeignKeyIfMissing(
            'orders',
            'orders_plan_course_identity_foreign',
            ['access_plan_id', 'course_id'],
            'course_access_plans',
            ['id', 'course_id']
        );
        $this->addForeignKeyIfMissing(
            'course_enrollments',
            'enrollments_plan_course_identity_foreign',
            ['access_plan_id', 'course_id'],
            'course_access_plans',
            ['id', 'course_id']
        );
        $this->addForeignKeyIfMissing(
            'ai_usage_events',
            'ai_events_plan_course_identity_foreign',
            ['access_plan_id', 'course_id'],
            'course_access_plans',
            ['id', 'course_id']
        );

        $this->addCheckIfMissing(
            'course_access_plans',
            'course_access_plans_contract_check',
            <<<'SQL'
(
    code IN ('basic', 'guided', 'mentor')
    AND CHAR_LENGTH(TRIM(name_ar)) > 0
    AND project_feedback_level IN ('pass_only', 'report', 'enhanced')
    AND chat_enabled IN (0, 1)
    AND project_output_enabled IN (0, 1)
    AND certificate_enabled IN (0, 1)
    AND is_active IN (0, 1)
    AND max_output_tokens > 0
    AND (
        (
            chat_enabled = 0
            AND chat_message_limit = 0
            AND chat_token_budget = 0
            AND ai_budget_usd = 0
            AND request_reserve_usd = 0
        )
        OR (
            chat_enabled = 1
            AND chat_message_limit > 0
            AND chat_token_budget > 0
            AND ai_budget_usd > 0
            AND request_reserve_usd > 0
            AND request_reserve_usd <= ai_budget_usd
        )
    )
    AND (
        (
            project_feedback_level = 'pass_only'
            AND project_feedback_token_budget = 0
            AND project_feedback_budget_usd = 0
            AND project_feedback_reserve_usd = 0
            AND project_output_enabled = 0
        )
        OR (
            project_feedback_level IN ('report', 'enhanced')
            AND project_feedback_token_budget > 0
            AND project_feedback_budget_usd > 0
            AND project_feedback_reserve_usd > 0
            AND project_feedback_reserve_usd <= project_feedback_budget_usd
            AND (project_output_enabled = 0 OR project_feedback_level = 'enhanced')
        )
    )
)
SQL
        );
        $this->addCheckIfMissing(
            'orders',
            'orders_access_plan_snapshot_check',
            $this->snapshotCheckExpression()
        );
        $this->addCheckIfMissing(
            'course_enrollments',
            'enrollments_access_plan_snapshot_check',
            $this->snapshotCheckExpression(true)
        );
        $this->addCheckIfMissing(
            'ai_entitlement_usages',
            'ai_entitlement_usages_feature_check',
            "(feature IN ('course_chat', 'project_feedback'))"
        );
        $this->addCheckIfMissing(
            'ai_usage_events',
            'ai_usage_events_state_check',
            "(feature IN ('course_chat', 'project_feedback') "
                . "AND status IN ('reserved', 'completed', 'failed', 'expired', 'cancelled'))"
        );
        $this->addCheckIfMissing(
            'orders',
            'orders_parent_precedes_check',
            '(parent_order_id IS NULL OR parent_order_id < id)'
        );
    }

    public function down(): void
    {
        if ($this->isMySql()) {
            foreach (self::CHECKS as $checkName) {
                $this->dropCheckIfPresent($this->tableForCheck($checkName), $checkName);
            }

            $this->dropForeignKeyIfPresent(
                'orders',
                'orders_parent_order_identity_foreign'
            );
            $this->dropForeignKeyIfPresent(
                'course_enrollments',
                'course_enrollments_order_identity_foreign'
            );
            $this->dropForeignKeyIfPresent(
                'course_enrollments',
                'enrollments_plan_order_identity_foreign'
            );
            $this->dropForeignKeyIfPresent('orders', 'orders_plan_course_identity_foreign');
            $this->dropForeignKeyIfPresent(
                'course_enrollments',
                'enrollments_plan_course_identity_foreign'
            );
            $this->dropForeignKeyIfPresent(
                'ai_usage_events',
                'ai_events_plan_course_identity_foreign'
            );

            $this->addNullablePlanForeignKeyIfMissing(
                'orders',
                'orders_access_plan_id_foreign'
            );
            $this->addNullablePlanForeignKeyIfMissing(
                'course_enrollments',
                'course_enrollments_access_plan_id_foreign'
            );
        }

        foreach (array_reverse(self::INDEXES, true) as $tableName => $indexes) {
            foreach (array_reverse($indexes, true) as $indexName => $_columns) {
                if ($this->indexExists($tableName, $indexName)) {
                    Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                        $table->dropIndex($indexName);
                    });
                }
            }
        }

        if (Schema::hasColumn('ai_usage_events', 'reservation_expires_at')) {
            Schema::table('ai_usage_events', function (Blueprint $table): void {
                $table->dropColumn('reservation_expires_at');
            });
        }
        if (Schema::hasColumn('financial_entitlement_holds', 'plan_reverted_at')) {
            Schema::table('financial_entitlement_holds', function (Blueprint $table): void {
                $table->dropColumn('plan_reverted_at');
            });
        }
        if (Schema::hasColumn('course_enrollments', 'access_plan_order_id')) {
            Schema::table('course_enrollments', function (Blueprint $table): void {
                $table->dropColumn('access_plan_order_id');
            });
        }
        if (Schema::hasColumn('orders', 'parent_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('parent_order_id');
            });
        }
    }

    private function assertRequiredSchema(): void
    {
        foreach (array_keys(self::INDEXES) as $tableName) {
            if (!Schema::hasTable($tableName)) {
                throw new RuntimeException(
                    "Cannot harden course plans because required table [{$tableName}] is missing."
                );
            }
        }

        if (!Schema::hasTable('ai_entitlement_usages')) {
            throw new RuntimeException(
                'Cannot harden course plans because required table [ai_entitlement_usages] is missing.'
            );
        }
        if (!Schema::hasTable('financial_entitlement_holds')) {
            throw new RuntimeException(
                'Cannot harden course plans because required table [financial_entitlement_holds] is missing.'
            );
        }
    }

    private function addLineageColumnsIfMissing(): void
    {
        if (!Schema::hasColumn('orders', 'parent_order_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('parent_order_id')->nullable()->after('access_plan_snapshot');
            });
        }
        if (!Schema::hasColumn('course_enrollments', 'access_plan_order_id')) {
            Schema::table('course_enrollments', function (Blueprint $table): void {
                $table->unsignedBigInteger('access_plan_order_id')
                    ->nullable()
                    ->after('access_plan_snapshot');
            });
        }
        if (!Schema::hasColumn('financial_entitlement_holds', 'plan_reverted_at')) {
            Schema::table('financial_entitlement_holds', function (Blueprint $table): void {
                $table->timestamp('plan_reverted_at')
                    ->nullable()
                    ->after('enrollment_deactivated_at');
            });
        }
    }

    private function backfillOrderLineage(): void
    {
        DB::table('course_enrollments')
            ->whereNotNull('access_plan_id')
            ->whereNotNull('order_id')
            ->whereNull('access_plan_order_id')
            ->update(['access_plan_order_id' => DB::raw('order_id')]);

        DB::table('orders')
            ->whereNull('parent_order_id')
            ->where(function ($query): void {
                $query->where('notes', 'like', 'Course access-plan upgrade from order #%')
                    ->orWhere('notes', 'like', 'Full-track upgrade from grant order #%')
                    ->orWhere('notes', 'like', 'Rokn AI/full-access upgrade from grant order #%');
            })
            ->orderBy('id')
            ->chunkById(250, function ($orders): void {
                foreach ($orders as $order) {
                    if (!preg_match(
                        '/^(?:Course access-plan upgrade from order|'
                        . 'Full-track upgrade from grant order|'
                        . 'Rokn AI\/full-access upgrade from grant order) #(\d+)$/D',
                        (string) $order->notes,
                        $matches
                    )) {
                        continue;
                    }
                    $parentId = (int) $matches[1];
                    $parent = DB::table('orders')->where('id', $parentId)->first();
                    if (
                        $parent === null
                        || $parentId >= (int) $order->id
                        || (int) $parent->user_id !== (int) $order->user_id
                        || (int) $parent->course_id !== (int) $order->course_id
                    ) {
                        throw new RuntimeException(
                            'Cannot safely infer the parent of upgrade order #' . (int) $order->id . '.'
                        );
                    }
                    DB::table('orders')->where('id', $order->id)->update([
                        'parent_order_id' => $parentId,
                    ]);
                }
            });
    }

    /** Validate every invariant before adding indexes and constraints. */
    private function assertExistingDataCanBeConstrained(): void
    {
        $duplicateSort = DB::table('course_access_plans')
            ->select('course_id', 'sort_order')
            ->groupBy('course_id', 'sort_order')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateSort !== null) {
            throw new RuntimeException(
                'Duplicate access-plan sort orders must be reconciled for course '
                . (int) $duplicateSort->course_id . ' before deployment.'
            );
        }

        $invalidPlan = DB::table('course_access_plans')
            ->where(function ($query): void {
                $query->whereNotIn('code', ['basic', 'guided', 'mentor'])
                    ->orWhereNotIn('project_feedback_level', ['pass_only', 'report', 'enhanced'])
                    ->orWhereNotIn('chat_enabled', [0, 1])
                    ->orWhereNotIn('project_output_enabled', [0, 1])
                    ->orWhereNotIn('certificate_enabled', [0, 1])
                    ->orWhereNotIn('is_active', [0, 1])
                    ->orWhereRaw("TRIM(name_ar) = ''")
                    ->orWhere('max_output_tokens', '<=', 0)
                    ->orWhereRaw(
                        '(chat_enabled = 0 AND ('
                        . 'chat_message_limit <> 0 OR chat_token_budget <> 0 '
                        . 'OR ai_budget_usd <> 0 OR request_reserve_usd <> 0))'
                    )
                    ->orWhereRaw(
                        '(chat_enabled = 1 AND ('
                        . 'chat_message_limit = 0 OR chat_token_budget = 0 '
                        . 'OR ai_budget_usd <= 0 OR request_reserve_usd <= 0 '
                        . 'OR request_reserve_usd > ai_budget_usd))'
                    )
                    ->orWhereRaw(
                        "(project_feedback_level = 'pass_only' AND ("
                        . 'project_feedback_token_budget <> 0 '
                        . 'OR project_feedback_budget_usd <> 0 '
                        . 'OR project_feedback_reserve_usd <> 0 '
                        . 'OR project_output_enabled <> 0))'
                    )
                    ->orWhereRaw(
                        "(project_feedback_level IN ('report', 'enhanced') AND ("
                        . 'project_feedback_token_budget = 0 '
                        . 'OR project_feedback_budget_usd <= 0 '
                        . 'OR project_feedback_reserve_usd <= 0 '
                        . 'OR project_feedback_reserve_usd > project_feedback_budget_usd))'
                    )
                    ->orWhereRaw(
                        "(project_output_enabled = 1 AND project_feedback_level <> 'enhanced')"
                    );
            })
            ->value('id');
        if ($invalidPlan !== null) {
            throw new RuntimeException(
                'Access plan #' . (int) $invalidPlan
                . ' violates the commercial-contract invariants and must be reconciled before deployment.'
            );
        }

        $invalidUsage = DB::table('ai_entitlement_usages')
            ->whereNotIn('feature', ['course_chat', 'project_feedback'])
            ->value('id');
        if ($invalidUsage !== null) {
            throw new RuntimeException(
                'AI entitlement usage #' . (int) $invalidUsage
                . ' contains an unsupported metered feature.'
            );
        }

        $invalidEvent = DB::table('ai_usage_events')
            ->where(function ($query): void {
                $query->whereNotIn('feature', ['course_chat', 'project_feedback'])
                    ->orWhereNotIn(
                        'status',
                        ['reserved', 'completed', 'failed', 'expired', 'cancelled']
                    );
            })
            ->value('id');
        if ($invalidEvent !== null) {
            throw new RuntimeException(
                'AI usage event #' . (int) $invalidEvent
                . ' contains an unsupported feature or lifecycle state.'
            );
        }

        foreach (['orders', 'course_enrollments'] as $tableName) {
            $missingSnapshot = DB::table($tableName)
                ->whereNotNull('access_plan_id')
                ->whereNull('access_plan_snapshot')
                ->value('id');
            if ($missingSnapshot !== null) {
                throw new RuntimeException(
                    "{$tableName} row #" . (int) $missingSnapshot
                    . ' has an access plan without an immutable snapshot.'
                );
            }

            if ($this->isMySql()) {
                $invalidSnapshot = DB::table($tableName)
                    ->whereNotNull('access_plan_id')
                    ->whereRaw(
                        '(JSON_SCHEMA_VALID(?, access_plan_snapshot) <> 1 '
                        . "OR CAST(JSON_UNQUOTE(JSON_EXTRACT(access_plan_snapshot, '$.plan_id')) "
                        . 'AS UNSIGNED) <> access_plan_id)',
                        [$this->snapshotJsonSchema()]
                    )
                    ->value('id');
                if ($invalidSnapshot !== null) {
                    throw new RuntimeException(
                        "{$tableName} row #" . (int) $invalidSnapshot
                        . ' contains a malformed or mismatched access-plan snapshot.'
                    );
                }
            }

            $mismatchedPlan = DB::table($tableName . ' as subject')
                ->leftJoin(
                    'course_access_plans as plan',
                    'plan.id',
                    '=',
                    'subject.access_plan_id'
                )
                ->whereNotNull('subject.access_plan_id')
                ->where(function ($query): void {
                    $query->whereNull('plan.id')
                        ->orWhereColumn('subject.course_id', '<>', 'plan.course_id');
                })
                ->value('subject.id');
            if ($mismatchedPlan !== null) {
                throw new RuntimeException(
                    "{$tableName} row #" . (int) $mismatchedPlan
                    . ' points to an access plan owned by a different course.'
                );
            }
        }

        $mismatchedAiEvent = DB::table('ai_usage_events as event')
            ->leftJoin('course_access_plans as plan', 'plan.id', '=', 'event.access_plan_id')
            ->whereNotNull('event.access_plan_id')
            ->where(function ($query): void {
                $query->whereNull('plan.id')
                    ->orWhereColumn('event.course_id', '<>', 'plan.course_id');
            })
            ->value('event.id');
        if ($mismatchedAiEvent !== null) {
            throw new RuntimeException(
                'AI usage event #' . (int) $mismatchedAiEvent
                . ' points to an access plan owned by a different course.'
            );
        }

        $orphanedOrder = DB::table('course_enrollments as enrollment')
            ->leftJoin('orders as source_order', 'source_order.id', '=', 'enrollment.order_id')
            ->whereNotNull('enrollment.order_id')
            ->whereNull('source_order.id')
            ->value('enrollment.id');
        if ($orphanedOrder !== null) {
            throw new RuntimeException(
                'Course enrollment #' . (int) $orphanedOrder
                . ' references a missing source order.'
            );
        }

        $mismatchedOrder = DB::table('course_enrollments as enrollment')
            ->join('orders as source_order', 'source_order.id', '=', 'enrollment.order_id')
            ->whereNotNull('enrollment.order_id')
            ->where(function ($query): void {
                $query->whereNull('source_order.user_id')
                    ->orWhereNull('source_order.course_id')
                    ->orWhereColumn('enrollment.user_id', '<>', 'source_order.user_id')
                    ->orWhereColumn('enrollment.course_id', '<>', 'source_order.course_id');
            })
            ->value('enrollment.id');
        if ($mismatchedOrder !== null) {
            throw new RuntimeException(
                'Course enrollment #' . (int) $mismatchedOrder
                . ' references an order for another learner or course.'
            );
        }

        $invalidParent = DB::table('orders as child')
            ->leftJoin('orders as parent', 'parent.id', '=', 'child.parent_order_id')
            ->whereNotNull('child.parent_order_id')
            ->where(function ($query): void {
                $query->whereNull('parent.id')
                    ->orWhereColumn('child.parent_order_id', '>=', 'child.id')
                    ->orWhereNull('parent.user_id')
                    ->orWhereNull('parent.course_id')
                    ->orWhereColumn('child.user_id', '<>', 'parent.user_id')
                    ->orWhereColumn('child.course_id', '<>', 'parent.course_id');
            })
            ->value('child.id');
        if ($invalidParent !== null) {
            throw new RuntimeException(
                'Upgrade order #' . (int) $invalidParent
                . ' has an invalid, forward, or cross-entitlement parent order.'
            );
        }

        $invalidPlanOrder = DB::table('course_enrollments as enrollment')
            ->leftJoin(
                'orders as plan_order',
                'plan_order.id',
                '=',
                'enrollment.access_plan_order_id'
            )
            ->whereNotNull('enrollment.access_plan_id')
            ->where(function ($query): void {
                $query->whereNull('enrollment.access_plan_order_id')
                    ->orWhereNull('plan_order.id')
                    ->orWhereColumn('enrollment.user_id', '<>', 'plan_order.user_id')
                    ->orWhereColumn('enrollment.course_id', '<>', 'plan_order.course_id')
                    ->orWhereColumn('enrollment.access_plan_id', '<>', 'plan_order.access_plan_id');
            })
            ->value('enrollment.id');
        if ($invalidPlanOrder !== null) {
            throw new RuntimeException(
                'Course enrollment #' . (int) $invalidPlanOrder
                . ' has no matching order for its current access-plan contract.'
            );
        }
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(
        string $tableName,
        array $columns,
        string $indexName,
        bool $unique
    ): void {
        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $columns,
            $indexName,
            $unique
        ): void {
            if ($unique) {
                $table->unique($columns, $indexName);
            } else {
                $table->index($columns, $indexName);
            }
        });
    }

    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    private function addForeignKeyIfMissing(
        string $tableName,
        string $constraintName,
        array $columns,
        string $referencedTable,
        array $referencedColumns
    ): void {
        if ($this->foreignKeyExists($tableName, $constraintName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $constraintName,
            $columns,
            $referencedTable,
            $referencedColumns
        ): void {
            $table->foreign($columns, $constraintName)
                ->references($referencedColumns)
                ->on($referencedTable)
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    private function addNullablePlanForeignKeyIfMissing(
        string $tableName,
        string $constraintName
    ): void {
        if ($this->foreignKeyExists($tableName, $constraintName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
            $table->foreign('access_plan_id', $constraintName)
                ->references('id')
                ->on('course_access_plans')
                ->nullOnDelete();
        });
    }

    private function addCheckIfMissing(
        string $tableName,
        string $constraintName,
        string $expression
    ): void {
        if ($this->checkExists($tableName, $constraintName)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK %s',
            $this->quoteTable($tableName),
            $this->quoteIdentifier($constraintName),
            $expression
        ));
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();
        $physicalTable = DB::connection()->getTablePrefix() . $tableName;

        if ($driver === 'sqlite') {
            $quoted = str_replace("'", "''", $physicalTable);
            foreach (DB::select("PRAGMA index_list('{$quoted}')") as $index) {
                if ((string) ($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($this->isMySql()) {
            return DB::selectOne(
                'SELECT 1 AS present FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$physicalTable, $indexName]
            ) !== null;
        }

        return false;
    }

    private function foreignKeyExists(string $tableName, string $constraintName): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.table_constraints '
            . "WHERE constraint_schema = DATABASE() AND table_name = ? "
            . "AND constraint_name = ? AND constraint_type = 'FOREIGN KEY' LIMIT 1",
            [DB::connection()->getTablePrefix() . $tableName, $constraintName]
        ) !== null;
    }

    private function checkExists(string $tableName, string $constraintName): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM information_schema.table_constraints '
            . "WHERE constraint_schema = DATABASE() AND table_name = ? "
            . "AND constraint_name = ? AND constraint_type = 'CHECK' LIMIT 1",
            [DB::connection()->getTablePrefix() . $tableName, $constraintName]
        ) !== null;
    }

    private function dropForeignKeyIfPresent(string $tableName, string $constraintName): void
    {
        if (!$this->foreignKeyExists($tableName, $constraintName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($constraintName): void {
            $table->dropForeign($constraintName);
        });
    }

    private function dropCheckIfPresent(string $tableName, string $constraintName): void
    {
        if (!$this->checkExists($tableName, $constraintName)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s DROP CHECK %s',
            $this->quoteTable($tableName),
            $this->quoteIdentifier($constraintName)
        ));
    }

    private function tableForCheck(string $checkName): string
    {
        return match ($checkName) {
            'course_access_plans_contract_check' => 'course_access_plans',
            'orders_access_plan_snapshot_check' => 'orders',
            'enrollments_access_plan_snapshot_check' => 'course_enrollments',
            'ai_entitlement_usages_feature_check' => 'ai_entitlement_usages',
            'ai_usage_events_state_check' => 'ai_usage_events',
            'orders_parent_precedes_check' => 'orders',
            default => throw new LogicException("Unknown check constraint [{$checkName}]."),
        };
    }

    private function snapshotCheckExpression(bool $requirePlanOrder = false): string
    {
        $schema = "'" . str_replace("'", "''", $this->snapshotJsonSchema()) . "'";

        return '(access_plan_id IS NULL OR ('
            . 'access_plan_snapshot IS NOT NULL '
            . ($requirePlanOrder ? 'AND access_plan_order_id IS NOT NULL ' : '')
            . "AND JSON_SCHEMA_VALID({$schema}, access_plan_snapshot) = 1 "
            . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(access_plan_snapshot, '$.plan_id')) "
            . 'AS UNSIGNED) = access_plan_id))';
    }

    private function snapshotJsonSchema(): string
    {
        $moneyKeys = [
            'ai_budget_usd',
            'request_reserve_usd',
            'project_feedback_budget_usd',
            'project_feedback_reserve_usd',
        ];
        $v1MoneyProperties = [];
        $v2MoneyProperties = [];
        foreach ($moneyKeys as $moneyKey) {
            $v1MoneyProperties[$moneyKey] = ['type' => 'number', 'minimum' => 0];
            $v2MoneyProperties[$moneyKey] = [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ];
        }

        return (string) json_encode([
            'type' => 'object',
            'required' => [
                'version',
                'plan_id',
                'code',
                'name_ar',
                'price_coins',
                'chat_enabled',
                'chat_message_limit',
                'chat_token_budget',
                'ai_budget_usd',
                'request_reserve_usd',
                'project_feedback_token_budget',
                'project_feedback_budget_usd',
                'project_feedback_reserve_usd',
                'max_output_tokens',
                'model_override',
                'project_feedback_level',
                'project_output_enabled',
                'certificate_enabled',
                'purchased_at',
            ],
            'properties' => [
                'version' => ['type' => 'integer', 'enum' => [1, 2]],
                'plan_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'enum' => ['basic', 'guided', 'mentor']],
                'name_ar' => ['type' => 'string', 'minLength' => 1],
                'price_coins' => ['type' => 'integer', 'minimum' => 0],
                'chat_enabled' => ['type' => 'boolean'],
                'chat_message_limit' => ['type' => 'integer', 'minimum' => 0],
                'chat_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
                'ai_budget_usd' => ['type' => ['number', 'string']],
                'request_reserve_usd' => ['type' => ['number', 'string']],
                'project_feedback_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'project_feedback_budget_usd' => ['type' => ['number', 'string']],
                'project_feedback_reserve_usd' => ['type' => ['number', 'string']],
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
            'oneOf' => [
                [
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [1]]],
                        $v1MoneyProperties
                    ),
                ],
                [
                    'required' => ['sort_order'],
                    'properties' => array_merge(
                        [
                            'version' => ['type' => 'integer', 'enum' => [2]],
                            'sort_order' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        $v2MoneyProperties
                    ),
                ],
            ],
            'additionalProperties' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function isMySql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function quoteTable(string $tableName): string
    {
        return $this->quoteIdentifier(DB::connection()->getTablePrefix() . $tableName);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
};
