<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCourseMappingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('course_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('old_id'); // ID from item_lists table
            $table->unsignedBigInteger('new_id'); // ID from courses table
            $table->timestamps();
            
            $table->index(['old_id', 'new_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('course_mappings');
    }
}
