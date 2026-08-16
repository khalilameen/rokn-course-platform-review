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
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->enum('platform', ['android', 'ios']);
            $table->string('version_name'); // For display (e.g. 1.0.0)
            $table->integer('version_code')->nullable(); // For Android logic
            $table->integer('build_number')->nullable(); // For iOS logic
            $table->boolean('is_force_update')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('update_message_ar')->nullable();
            $table->text('update_message_en')->nullable();
            $table->string('download_url')->nullable();
            $table->text('release_notes_ar')->nullable();
            $table->text('release_notes_en')->nullable();
            $table->timestamps();
            
            // Indexes for faster lookups
            $table->index(['platform', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('app_versions');
    }
};
