<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('packages', 'is_active')) {
            Schema::table('packages', fn (Blueprint $table) =>
                $table->boolean('is_active')->default(true)->after('coins')
            );
        }
        if (!Schema::hasColumn('packages', 'direct_enabled')) {
            Schema::table('packages', fn (Blueprint $table) =>
                $table->boolean('direct_enabled')->default(true)->after('is_active')
            );
        }
        if (!Schema::hasIndex('packages', ['is_active'])) {
            Schema::table('packages', fn (Blueprint $table) => $table->index('is_active'));
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'direct_enabled']);
        });
    }
};
