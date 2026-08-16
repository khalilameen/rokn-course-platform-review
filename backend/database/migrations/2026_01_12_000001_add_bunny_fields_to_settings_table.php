<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBunnyFieldsToSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('bunny_enabled')->default(false)->after('enforce_course_section_order');
            $table->string('bunny_api_key')->nullable()->after('bunny_enabled');
            $table->string('bunny_library_id')->nullable()->after('bunny_api_key');
            $table->string('bunny_cdn_hostname')->nullable()->after('bunny_library_id');
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
            $table->dropColumn([
                'bunny_enabled',
                'bunny_api_key',
                'bunny_library_id',
                'bunny_cdn_hostname'
            ]);
        });
    }
}

