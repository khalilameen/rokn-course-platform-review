<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('project_feedback_threads')) {
            Schema::create('project_feedback_threads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('submission_id')->unique()->constrained('project_submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
            $table->foreignId('access_plan_id')->nullable()->constrained('course_access_plans')->nullOnDelete();
            $table->string('feedback_level', 24);
            $table->boolean('can_reply')->default(false);
            $table->string('status', 24)->default('ready');
            $table->timestamps();

            $table->index(['user_id', 'course_id', 'updated_at'], 'project_feedback_thread_history');
            });
        }

        if (!Schema::hasTable('project_feedback_messages')) {
            Schema::create('project_feedback_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('thread_id')->constrained('project_feedback_threads')->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('client_request_id', 100)->nullable();
            $table->string('status', 24)->default('queued');
            $table->text('body')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->foreignId('usage_event_id')->nullable()->constrained('ai_usage_events')->nullOnDelete();
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'client_request_id'], 'project_feedback_message_idempotency');
            $table->index(['thread_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_feedback_messages');
        Schema::dropIfExists('project_feedback_threads');
    }
};
