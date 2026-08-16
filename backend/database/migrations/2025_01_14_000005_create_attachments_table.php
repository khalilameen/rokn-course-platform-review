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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable'); // attachable_type, attachable_id (for module or section)
            $table->string('title');
            $table->string('file_path');
            $table->string('file_type')->nullable(); // pdf, zip, doc, etc.
            $table->bigInteger('file_size')->nullable(); // in bytes
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->index(['attachable_type', 'attachable_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('attachments');
    }
};
