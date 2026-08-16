<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects') || !Schema::hasColumn('projects', 'fallback_review_delay_seconds')) {
            return;
        }

        DB::table('projects')
            ->whereNotNull('fallback_review_delay_seconds')
            ->where('fallback_review_delay_seconds', '<', 30)
            ->update(['fallback_review_delay_seconds' => 90]);
    }

    public function down(): void
    {
        // The original per-project values cannot be reconstructed safely.
    }
};
