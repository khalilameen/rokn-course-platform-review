<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('courses')) {
            $addEnabled = !Schema::hasColumn('courses', 'chat_attachments_enabled');
            $addMaximum = !Schema::hasColumn('courses', 'chat_attachment_max_files');
            if ($addEnabled || $addMaximum) {
                Schema::table('courses', function (Blueprint $table) use ($addEnabled, $addMaximum): void {
                    if ($addEnabled) $table->boolean('chat_attachments_enabled')->default(false);
                    if ($addMaximum) $table->unsignedTinyInteger('chat_attachment_max_files')->default(1);
                });
            }
        }
        if (Schema::hasTable('course_access_plans')) {
            $columns = [
                'chat_attachments_enabled' => !Schema::hasColumn('course_access_plans', 'chat_attachments_enabled'),
                'chat_attachment_max_files' => !Schema::hasColumn('course_access_plans', 'chat_attachment_max_files'),
                'project_followup_attachments_enabled' => !Schema::hasColumn('course_access_plans', 'project_followup_attachments_enabled'),
                'project_followup_attachment_max_files' => !Schema::hasColumn('course_access_plans', 'project_followup_attachment_max_files'),
            ];
            if (in_array(true, $columns, true)) {
                Schema::table('course_access_plans', function (Blueprint $table) use ($columns): void {
                    if ($columns['chat_attachments_enabled']) $table->boolean('chat_attachments_enabled')->default(false);
                    if ($columns['chat_attachment_max_files']) $table->unsignedTinyInteger('chat_attachment_max_files')->default(0);
                    if ($columns['project_followup_attachments_enabled']) $table->boolean('project_followup_attachments_enabled')->default(false);
                    if ($columns['project_followup_attachment_max_files']) $table->unsignedTinyInteger('project_followup_attachment_max_files')->default(0);
                });
            }
        }
        if (Schema::hasTable('projects')) {
            $addMaximum = !Schema::hasColumn('projects', 'submission_max_files');
            $addMimeTypes = !Schema::hasColumn('projects', 'submission_allowed_mime_types');
            if ($addMaximum || $addMimeTypes) {
                Schema::table('projects', function (Blueprint $table) use ($addMaximum, $addMimeTypes): void {
                    if ($addMaximum) $table->unsignedTinyInteger('submission_max_files')->default(3);
                    if ($addMimeTypes) $table->json('submission_allowed_mime_types')->nullable();
                });
            }
        }
        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'ai_chat_enabled')) {
            DB::table('courses')->where('ai_chat_enabled', true)->update([
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => 2,
            ]);
        }
        if (Schema::hasTable('course_access_plans')) {
            DB::table('course_access_plans')->where('chat_enabled', true)->update([
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => 2,
            ]);
            DB::table('course_access_plans')->where('project_feedback_level', 'enhanced')->update([
                'project_followup_attachments_enabled' => true,
                'project_followup_attachment_max_files' => 2,
            ]);
        }
        if (!Schema::hasTable('ai_input_attachments')) {
            Schema::create('ai_input_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_upload_id');
            $table->string('purpose', 32);
            $table->string('owner_type', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('storage_disk', 64);
            $table->string('storage_path');
            $table->string('original_file_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 24)->default('ready');
            $table->json('provider_annotations')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_upload_id']);
            $table->index(['owner_type', 'owner_id']);
            $table->index(['user_id', 'course_id', 'status']);
            });
        }
        if (Schema::hasTable('project_feedback_messages')) {
            $addAnnotations = !Schema::hasColumn('project_feedback_messages', 'provider_annotations');
            $addFingerprint = !Schema::hasColumn('project_feedback_messages', 'attachment_request_fingerprint');
            $addCount = !Schema::hasColumn('project_feedback_messages', 'attachment_count');
            if ($addAnnotations || $addFingerprint || $addCount) {
                Schema::table('project_feedback_messages', function (Blueprint $table) use ($addAnnotations, $addFingerprint, $addCount): void {
                    if ($addAnnotations) $table->json('provider_annotations')->nullable();
                    if ($addFingerprint) $table->char('attachment_request_fingerprint', 64)->nullable();
                    if ($addCount) $table->unsignedTinyInteger('attachment_count')->default(0);
                });
            }
        }
        if (Schema::hasTable('course_chat_turns') && !Schema::hasColumn('course_chat_turns', 'attachment_count')) {
            Schema::table('course_chat_turns', function (Blueprint $table): void {
                $table->unsignedTinyInteger('attachment_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_feedback_messages')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('project_feedback_messages', 'provider_annotations') ? 'provider_annotations' : null,
                Schema::hasColumn('project_feedback_messages', 'attachment_request_fingerprint') ? 'attachment_request_fingerprint' : null,
                Schema::hasColumn('project_feedback_messages', 'attachment_count') ? 'attachment_count' : null,
            ]));
            if ($columns !== []) Schema::table('project_feedback_messages', fn (Blueprint $table) => $table->dropColumn($columns));
        }
        if (Schema::hasTable('course_chat_turns') && Schema::hasColumn('course_chat_turns', 'attachment_count')) {
            Schema::table('course_chat_turns', function (Blueprint $table): void {
                $table->dropColumn('attachment_count');
            });
        }
        Schema::dropIfExists('ai_input_attachments');
        $this->dropExistingColumns('projects', ['submission_max_files', 'submission_allowed_mime_types']);
        $this->dropExistingColumns('course_access_plans', [
            'chat_attachments_enabled', 'chat_attachment_max_files',
            'project_followup_attachments_enabled', 'project_followup_attachment_max_files',
        ]);
        $this->dropExistingColumns('courses', ['chat_attachments_enabled', 'chat_attachment_max_files']);
    }

    private function dropExistingColumns(string $tableName, array $columns): void
    {
        if (!Schema::hasTable($tableName)) return;
        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($tableName, $column)
        ));
        if ($existing !== []) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
