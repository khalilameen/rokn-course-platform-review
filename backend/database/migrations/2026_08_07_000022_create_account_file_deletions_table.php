<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('disk', 64);
            $table->string('path_hash', 64);
            $table->text('path')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_file_deletions');
    }
};
