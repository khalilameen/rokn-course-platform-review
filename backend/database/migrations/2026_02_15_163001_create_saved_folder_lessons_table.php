<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSavedFolderLessonsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('saved_folder_lessons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('saved_folder_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('saved_folder_id')
                  ->references('id')
                  ->on('saved_folders')
                  ->onDelete('cascade');

            $table->foreign('lesson_id')
                  ->references('id')
                  ->on('lessons')
                  ->onDelete('cascade');

            // Unique constraint to prevent duplicate lesson saves in same folder
            $table->unique(['saved_folder_id', 'lesson_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('saved_folder_lessons');
    }
}
