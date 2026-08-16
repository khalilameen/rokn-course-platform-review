<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {//'title',	'question','question_image',	'description', 'priority' ,'choice1',	'choice2',	'cho
        Schema::create('exams_result_details', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('question');
            $table->string('question_image');
            $table->text('description');
            $table->boolean('right');
            $table->string('student_choice');
            $table->string('right_choice')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams_result_detailss');
    }
};
