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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->text('requirements_text')->nullable(); // Project requirements displayed to user
            $table->text('ai_prompt'); // Prompt used for AI evaluation
            $table->integer('passing_score')->default(50); // Minimum score to pass (percentage)
            $table->boolean('is_graduation_project')->default(false); // Flag for graduation project
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
        Schema::dropIfExists('projects');
    }
};
