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
        Schema::table('design_settings', function (Blueprint $table) {
            $table->boolean('show_how_platform_works')->default(true)->after('powered_by');
            $table->string('how_platform_works_title_ar')->nullable()->after('show_how_platform_works');
            $table->string('how_platform_works_title_en')->nullable()->after('how_platform_works_title_ar');
            $table->string('how_platform_works_video_link')->nullable()->after('how_platform_works_title_en');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('design_settings', function (Blueprint $table) {
            $table->dropColumn(['show_how_platform_works', 'how_platform_works_video_link']);
        });
    }
};
