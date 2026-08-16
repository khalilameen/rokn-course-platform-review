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
        Schema::table('course_modules', function (Blueprint $table) {
            $table->string('title_ar')->nullable()->after('course_id');
            $table->string('title_en')->nullable()->after('title_ar');
            $table->text('description_ar')->nullable()->after('description');
            $table->text('description_en')->nullable()->after('description_ar');
        });

        // Migrate existing data
        DB::table('course_modules')->whereNotNull('title')->update([
            'title_ar' => DB::raw('title')
        ]);
        
        DB::table('course_modules')->whereNotNull('description')->update([
            'description_ar' => DB::raw('description')
        ]);

        if (DB::connection()->getDriverName() === 'sqlite') {
            // The clean-install title is already nullable. Avoid rebuilding a
            // table referenced by course_sections.
            return;
        }

        // Make original columns nullable
        Schema::table('course_modules', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->text('description')->nullable()->change();
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

        Schema::table('course_modules', function (Blueprint $table) {
            $table->dropColumn(['title_ar', 'title_en', 'description_ar', 'description_en']);
            $table->string('title')->nullable(false)->change();
            $table->text('description')->nullable()->change();
        });
    }
};
