<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 32);
            $table->string('status', 24)->default('new');
            $table->string('priority', 16)->default('normal');
            $table->text('message');
            $table->string('screen_key', 64)->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->unsignedSmallInteger('os_major')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('screen_size', 32)->nullable();
            $table->decimal('font_scale', 4, 2)->nullable();
            $table->string('device_tier', 24)->nullable();
            $table->string('network_type', 24)->nullable();
            $table->json('context')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['app_version', 'created_at']);
        });

        Schema::create('feedback_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_report_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('feedback');
            $table->string('path', 500);
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_reports');
    }
};
