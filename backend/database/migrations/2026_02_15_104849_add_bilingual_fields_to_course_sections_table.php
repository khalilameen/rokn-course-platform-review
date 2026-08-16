<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('course_sections', function (Blueprint $table) {
            $table->string('title_ar')->nullable()->after('course_id');
            $table->string('title_en')->nullable()->after('title_ar');
        });

        // Migrate existing data
        DB::table('course_sections')->whereNotNull('title')->update([
            'title_ar' => DB::raw('title')
        ]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Make original column nullable
        Schema::table('course_sections', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('course_sections', function (Blueprint $table) {
            $table->dropColumn(['title_ar', 'title_en']);
            $table->string('title')->nullable(false)->change();
        });
    }
};
