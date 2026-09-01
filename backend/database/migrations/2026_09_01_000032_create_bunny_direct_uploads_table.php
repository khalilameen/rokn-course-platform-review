<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bunny_direct_uploads')) {
            return;
        }

        Schema::create('bunny_direct_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('course_sections')->nullOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->uuid('video_guid')->nullable()->unique();
            $table->string('status', 24)->default('allocating');
            $table->timestamp('expires_at');
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'idempotency_key'], 'bunny_direct_upload_idempotency_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunny_direct_uploads');
    }
};
