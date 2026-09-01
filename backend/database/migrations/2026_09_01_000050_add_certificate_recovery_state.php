<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $columns = [
            'recovery_attempts' => fn (Blueprint $table) => $table->unsignedTinyInteger('recovery_attempts')->default(0)->after('revoked_at'),
            'recovery_next_attempt_at' => fn (Blueprint $table) => $table->timestamp('recovery_next_attempt_at')->nullable()->after('recovery_attempts'),
            'recovery_failed_at' => fn (Blueprint $table) => $table->timestamp('recovery_failed_at')->nullable()->after('recovery_next_attempt_at'),
            'recovery_failure_code' => fn (Blueprint $table) => $table->string('recovery_failure_code', 64)->nullable()->after('recovery_failed_at'),
            'artifact_checked_at' => fn (Blueprint $table) => $table->timestamp('artifact_checked_at')->nullable()->after('recovery_failure_code'),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('certificates', $column)) {
                Schema::table('certificates', $definition);
            }
        }
        if (!Schema::hasIndex('certificates', 'certificates_recovery_due')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->index(
                    ['status', 'recovery_next_attempt_at', 'recovery_attempts'],
                    'certificates_recovery_due'
                )
            );
        }
        if (!Schema::hasIndex('certificates', 'certificates_artifact_audit')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->index(
                    ['status', 'artifact_checked_at'],
                    'certificates_artifact_audit'
                )
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('certificates', 'certificates_artifact_audit')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->dropIndex('certificates_artifact_audit')
            );
        }
        if (Schema::hasIndex('certificates', 'certificates_recovery_due')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->dropIndex('certificates_recovery_due')
            );
        }
        foreach ([
            'artifact_checked_at',
            'recovery_failure_code',
            'recovery_failed_at',
            'recovery_next_attempt_at',
            'recovery_attempts',
        ] as $column) {
            if (Schema::hasColumn('certificates', $column)) {
                Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
