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

        Schema::create('course_modules', function (Blueprint $table) use ($canReferenceCourses) {
            $table->id();
            $table->foreignId('course_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index(['course_id', 'order']);

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
        Schema::dropIfExists('course_modules');
    }
};
