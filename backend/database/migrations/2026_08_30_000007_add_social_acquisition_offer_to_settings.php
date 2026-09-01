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
        if (!Schema::hasTable('settings')) return;
        Schema::table('settings', function (Blueprint $table): void {
            if (!Schema::hasColumn('settings', 'recommended_social_provider')) $table->string('recommended_social_provider', 20)->default('facebook');
            if (!Schema::hasColumn('settings', 'recommended_provider_bonus_coins')) $table->unsignedInteger('recommended_provider_bonus_coins')->default(0);
            if (!Schema::hasColumn('settings', 'recommended_provider_badge_ar')) $table->string('recommended_provider_badge_ar')->nullable();
            if (!Schema::hasColumn('settings', 'recommended_provider_badge_en')) $table->string('recommended_provider_badge_en')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('settings')) return;
        $columns = array_values(array_filter([
            'recommended_social_provider', 'recommended_provider_bonus_coins',
            'recommended_provider_badge_ar', 'recommended_provider_badge_en',
        ], static fn (string $column): bool => Schema::hasColumn('settings', $column)));
        if ($columns !== []) Schema::table('settings', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
