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
        if (! Schema::hasTable('projects') || ! Schema::hasColumn('projects', 'section_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('projects') || Schema::hasColumn('projects', 'section_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->constrained('course_sections')->onDelete('cascade');
        });
    }
};
