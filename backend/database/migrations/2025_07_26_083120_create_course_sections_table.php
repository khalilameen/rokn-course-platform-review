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
        $canReferenceCourses = Schema::hasTable('courses')
            || Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('course_sections', function (Blueprint $table) use ($canReferenceCourses) {
            $table->id();
            $table->foreignId('course_id');
            $table->foreignId('module_id')->nullable()->constrained('course_modules')->nullOnDelete();
            $table->string('title')->nullable();
            $table->enum('section_type', ['lesson', 'project'])->default('lesson');
            $table->integer('order')->default(0);
            $table->morphs('sectionable'); // sectionable_id and sectionable_type
            $table->softDeletes();
            $table->timestamps();

            if ($canReferenceCourses) {
                $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('course_sections');
    }
};
