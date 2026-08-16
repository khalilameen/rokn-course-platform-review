<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Lists extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lists', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->bigInteger('course_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('type')->nullable();
            $table->integer('priority')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_opened')->default(false);
            $table->integer('time_minutes')->nullable()->comment('Exam time limit in minutes');
            $table->unsignedBigInteger('teacher_id')->nullable()->default(null);
            $table->unsignedBigInteger('parent_id')->nullable(); // For self-referencing relationship
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
        //
    }
}
