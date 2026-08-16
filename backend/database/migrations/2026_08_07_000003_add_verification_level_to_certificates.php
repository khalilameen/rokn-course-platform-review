<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            // A certificate always verifies course completion. It verifies
            // project quality only when a named dashboard reviewer accepted it.
            $table->string('verification_level', 32)
                ->default('completion')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table): void {
            $table->dropColumn('verification_level');
        });
    }
};
