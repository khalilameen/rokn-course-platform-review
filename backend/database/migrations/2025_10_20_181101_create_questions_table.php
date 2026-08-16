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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('question')->nullable();
            $table->string('question_image')->nullable();
            $table->text('description')->nullable();
            $table->integer('priority')->nullable();
            $table->string('choice1')->nullable();
            $table->string('choice2')->nullable();
            $table->string('choice3')->nullable();
            $table->string('choice4')->nullable();
            $table->string('choice5')->nullable();
            $table->string('choice6')->nullable();
            $table->string('right_answer')->nullable();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('list_id')->references('id')->on('lists')->onDelete('cascade');
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('questions');
    }
};
