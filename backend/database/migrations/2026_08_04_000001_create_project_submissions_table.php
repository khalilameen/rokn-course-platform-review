<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status', 30)->default('unknown');
            $table->string('review_status', 30)->default('pending');
            $table->string('review_source', 40)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('auto_pass_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'project_id', 'idempotency_key'], 'project_submission_idempotency');
            $table->index(['review_status', 'auto_pass_at']);
            $table->index(['user_id', 'project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_submissions');
    }
};
