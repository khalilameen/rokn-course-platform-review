<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('how_to_use_coins_ar')->nullable()->after('ios_app_url');
            $table->text('how_to_use_coins_en')->nullable()->after('how_to_use_coins_ar');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['how_to_use_coins_ar', 'how_to_use_coins_en']);
        });
    }
};
