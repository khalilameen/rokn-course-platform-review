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
        if (!Schema::hasTable('bunny_storage_cleanup_candidates')) {
            return;
        }
        if (!Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at')) {
            Schema::table('bunny_storage_cleanup_candidates', function (Blueprint $table): void {
                $table->timestamp('quarantined_at')->nullable()->after('completed_at');
            });
        }
        if (!Schema::hasIndex('bunny_storage_cleanup_candidates', 'bunny_storage_cleanup_dispatch')) {
            Schema::table('bunny_storage_cleanup_candidates', function (Blueprint $table): void {
                $table->index(
                    ['completed_at', 'quarantined_at', 'eligible_after'],
                    'bunny_storage_cleanup_dispatch'
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('bunny_storage_cleanup_candidates')
            || !Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at')) {
            return;
        }
        Schema::table('bunny_storage_cleanup_candidates', function (Blueprint $table): void {
            if (Schema::hasIndex('bunny_storage_cleanup_candidates', 'bunny_storage_cleanup_dispatch')) {
                $table->dropIndex('bunny_storage_cleanup_dispatch');
            }
            $table->dropColumn('quarantined_at');
        });
    }
};
