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
        Schema::create('user_project_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->integer('score')->default(0); // Score from 0-100
            $table->boolean('passed')->default(false); // Whether user passed the project
            $table->json('evaluation_data')->nullable(); // Store AI evaluation details, feedback, etc.
            $table->text('submission_text')->nullable(); // User's project submission
            $table->string('submission_file')->nullable(); // Optional file submission
            $table->timestamps();
            
            $table->index(['user_id', 'project_id']);
            $table->unique(['user_id', 'project_id']); // One evaluation per user per project
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_project_evaluations');
    }
};
