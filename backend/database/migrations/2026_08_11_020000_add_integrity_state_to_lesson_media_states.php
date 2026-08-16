<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('lesson_media_states')) {
            return;
        }

        Schema::table('lesson_media_states', function (Blueprint $table): void {
            if (!Schema::hasColumn('lesson_media_states', 'integrity_status')) {
                $table->string('integrity_status', 24)->default('unknown')->index();
            }
            if (!Schema::hasColumn('lesson_media_states', 'integrity_issues')) {
                $table->json('integrity_issues')->nullable();
            }
            if (!Schema::hasColumn('lesson_media_states', 'last_reconciled_at')) {
                $table->timestamp('last_reconciled_at')->nullable()->index();
            }
            if (!Schema::hasColumn('lesson_media_states', 'quarantined_at')) {
                $table->timestamp('quarantined_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('lesson_media_states')) {
            return;
        }

        // SQLite cannot drop an indexed column while the generated index is
        // still present. Drop the two additive indexes explicitly first; the
        // same order is safe for MySQL during a production rollback.
        Schema::table('lesson_media_states', function (Blueprint $table): void {
            if (Schema::hasIndex('lesson_media_states', ['last_reconciled_at'])) {
                $table->dropIndex(['last_reconciled_at']);
            }
            if (Schema::hasIndex('lesson_media_states', ['integrity_status'])) {
                $table->dropIndex(['integrity_status']);
            }
        });

        Schema::table('lesson_media_states', function (Blueprint $table): void {
            foreach ([
                'quarantined_at',
                'last_reconciled_at',
                'integrity_issues',
                'integrity_status',
            ] as $column) {
                if (Schema::hasColumn('lesson_media_states', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
