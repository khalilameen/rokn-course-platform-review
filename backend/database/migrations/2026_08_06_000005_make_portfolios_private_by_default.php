<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('portfolio_is_public')->default(false)->change();
            });
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->boolean('is_public')->default(false)->change();
            });
        }

        // Publishing is opt-in. Existing accounts must make that choice too.
        DB::table('users')->update(['portfolio_is_public' => false]);
        DB::table('portfolio_items')->update(['is_public' => false]);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('portfolio_is_public')->default(true)->change();
        });
        Schema::table('portfolio_items', function (Blueprint $table): void {
            $table->boolean('is_public')->default(true)->change();
        });

        // Deliberately do not republish records hidden by a privacy migration.
    }
};
