<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** MySQL DDL commits implicitly. Every step is guarded for safe retries. */
    public $withinTransaction = false;

    private const USAGE_CHECK = 'ai_entitlement_usages_feature_check';
    private const EVENT_CHECK = 'ai_usage_events_state_check';

    public function up(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        $this->replaceCheck(
            'ai_entitlement_usages',
            self::USAGE_CHECK,
            "feature IN ('course_chat', 'project_feedback', 'project_followup')"
        );
        $this->replaceCheck(
            'ai_usage_events',
            self::EVENT_CHECK,
            "feature IN ('course_chat', 'project_feedback', 'project_followup') "
                . "AND status IN ('reserved', 'completed', 'failed', 'expired', 'cancelled')"
        );
    }

    public function down(): void
    {
        if (!$this->isMySql()) {
            return;
        }

        $hasFollowupUsage = DB::table('ai_entitlement_usages')
            ->where('feature', 'project_followup')
            ->exists();
        $hasFollowupEvents = DB::table('ai_usage_events')
            ->where('feature', 'project_followup')
            ->exists();
        if ($hasFollowupUsage || $hasFollowupEvents) {
            throw new RuntimeException(
                'Cannot remove project_followup accounting constraints while usage history exists.'
            );
        }

        $this->replaceCheck(
            'ai_entitlement_usages',
            self::USAGE_CHECK,
            "feature IN ('course_chat', 'project_feedback')"
        );
        $this->replaceCheck(
            'ai_usage_events',
            self::EVENT_CHECK,
            "feature IN ('course_chat', 'project_feedback') "
                . "AND status IN ('reserved', 'completed', 'failed', 'expired', 'cancelled')"
        );
    }

    private function replaceCheck(string $table, string $name, string $expression): void
    {
        if ($this->checkExists($table, $name)) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` DROP CHECK `%s`',
                str_replace('`', '``', $table),
                str_replace('`', '``', $name)
            ));
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
            str_replace('`', '``', $table),
            str_replace('`', '``', $name),
            $expression
        ));
    }

    private function checkExists(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function isMySql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
