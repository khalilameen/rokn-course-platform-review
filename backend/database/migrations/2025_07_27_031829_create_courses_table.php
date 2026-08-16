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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->enum('course_type', ['center', 'online', 'both'])->default('online');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('teacher_id')->nullable()->default(null);
            $table->integer('grade_id')->nullable()->index();
            $table->string('name_ar')->nullable();;
            $table->string('name_en')->nullable();;
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('store_id')->nullable();
            $table->string('image')->nullable();;
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
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('courses');
    }
};
