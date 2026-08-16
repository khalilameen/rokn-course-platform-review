<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained('lessons')->cascadeOnDelete();
            $table->string('provider', 32)->default('bunny');
            $table->string('provider_media_id')->nullable()->index();
            $table->string('status', 24)->default('unknown')->index();
            $table->string('protocol', 16)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamps();
        });

        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('course_section_id')->nullable()->constrained('course_sections')->nullOnDelete();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->string('event_type', 24)->default('play');
            $table->string('end_reason', 32)->nullable();
            $table->string('source_protocol', 16)->nullable();
            $table->string('effective_quality', 16)->nullable();
            $table->string('source_host', 190)->nullable();
            $table->decimal('playback_rate', 4, 2)->default(1);
            $table->unsignedSmallInteger('recovery_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'lesson_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
        Schema::dropIfExists('lesson_media_states');
    }
};
