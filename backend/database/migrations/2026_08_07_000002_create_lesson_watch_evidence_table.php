<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_watch_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_section_id')->constrained('course_sections')->cascadeOnDelete();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('verified_seconds')->default(0);
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id'], 'lesson_watch_evidence_user_lesson_unique');
            $table->index(['user_id', 'course_section_id'], 'lesson_watch_evidence_user_section_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_watch_evidence');
    }
};
