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
        // Older production databases already had courses when this migration
        // was introduced. A clean installation creates courses later in the
        // historical sequence, where these columns are now part of the base
        // schema.
        if (! Schema::hasTable('courses')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->default(0);
            $table->decimal('price_before_discount', 10, 2)->nullable()->default(0);
            $table->string('currency', 10)->nullable()->default('جنيه');
            $table->integer('video_count')->nullable()->default(0);
            $table->integer('hours_count')->nullable()->default(0);
            $table->integer('questions_count')->nullable()->default(0);
            $table->integer('exam_count')->nullable()->default(0);
            $table->integer('home_work_count')->nullable()->default(0);
            $table->integer('files_count')->nullable()->default(0);
            $table->integer('students_count')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('courses')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn([
                'price',
                'price_before_discount',
                'currency',
                'video_count',
                'hours_count',
                'questions_count',
                'exam_count',
                'home_work_count',
                'files_count',
                'students_count'
            ]);
        });
    }
};
