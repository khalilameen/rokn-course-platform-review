<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('recommended_social_provider', 20)->default('facebook');
            $table->unsignedInteger('recommended_provider_bonus_coins')->default(0);
            $table->string('recommended_provider_badge_ar')->nullable();
            $table->string('recommended_provider_badge_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'recommended_social_provider',
                'recommended_provider_bonus_coins',
                'recommended_provider_badge_ar',
                'recommended_provider_badge_en',
            ]);
        });
    }
};
