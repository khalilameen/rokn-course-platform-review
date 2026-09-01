<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bunny_storage_cleanup_candidates')) {
            return;
        }

        Schema::create('bunny_storage_cleanup_candidates', function (Blueprint $table): void {
            $table->id();
            $table->char('path_hash', 64)->unique();
            $table->text('path')->nullable();
            $table->string('reason', 100);
            $table->timestamp('eligible_after')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunny_storage_cleanup_candidates');
    }
};
