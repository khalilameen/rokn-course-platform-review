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
        // Some clean installations create course_sections later in the legacy
        // timeline. Its owning create migration includes these columns there.
        if (! Schema::hasTable('course_sections')) {
            return;
        }

        Schema::table('course_sections', function (Blueprint $table) {
            $table->foreignId('module_id')->nullable()->after('course_id')->constrained('course_modules')->onDelete('set null');
            $table->enum('section_type', ['lesson', 'project'])->default('lesson')->after('title');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('course_sections')) {
            return;
        }

        Schema::table('course_sections', function (Blueprint $table) {
            $table->dropForeign(['module_id']);
            $table->dropColumn(['module_id', 'section_type']);
        });
    }
};
