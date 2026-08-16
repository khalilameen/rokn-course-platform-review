<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('android_app_url')->nullable();
            $table->text('ios_app_url')->nullable();
            $table->text('about_us_url')->nullable();
            $table->text('privacy_policy_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['android_app_url', 'ios_app_url', 'about_us_url', 'privacy_policy_url']);
        });
    }
};
