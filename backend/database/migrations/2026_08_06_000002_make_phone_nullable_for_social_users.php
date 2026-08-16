<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Clean installs already define phone as nullable; avoid rebuilding
            // users after social_accounts starts referencing it.
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Social accounts legitimately have no phone. Reverting to NOT NULL
        // would corrupt that invariant, so this migration is intentionally
        // forward-only.
    }
};
