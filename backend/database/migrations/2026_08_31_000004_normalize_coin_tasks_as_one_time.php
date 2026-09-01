<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('coin_earning_methods', 'is_repeatable')) {
            DB::table('coin_earning_methods')->update(['is_repeatable' => false]);
        }
    }

    public function down(): void
    {
        // Previous repeatable values were not executable and cannot be restored safely.
    }
};
