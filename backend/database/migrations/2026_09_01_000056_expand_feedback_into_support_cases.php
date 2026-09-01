<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('feedback_reports')) {
            return;
        }

        $columns = [
            'guest_access_hash' => fn (Blueprint $table) => $table->char('guest_access_hash', 64)->nullable(),
            'requester_email' => fn (Blueprint $table) => $table->string('requester_email', 254)->nullable(),
            'order_id' => fn (Blueprint $table) => $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(),
            'version' => fn (Blueprint $table) => $table->unsignedInteger('version')->default(1),
            'first_response_due_at' => fn (Blueprint $table) => $table->timestamp('first_response_due_at')->nullable(),
            'last_user_message_at' => fn (Blueprint $table) => $table->timestamp('last_user_message_at')->nullable(),
            'last_staff_message_at' => fn (Blueprint $table) => $table->timestamp('last_staff_message_at')->nullable(),
            'closed_at' => fn (Blueprint $table) => $table->timestamp('closed_at')->nullable(),
            'reopened_at' => fn (Blueprint $table) => $table->timestamp('reopened_at')->nullable(),
            'retention_until' => fn (Blueprint $table) => $table->timestamp('retention_until')->nullable(),
            'resolution_kind' => fn (Blueprint $table) => $table->string('resolution_kind', 32)->nullable(),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('feedback_reports', $column)) {
                Schema::table('feedback_reports', fn (Blueprint $table) => $definition($table));
            }
        }

        if (!Schema::hasIndex('feedback_reports', ['guest_access_hash'], 'unique')) {
            Schema::table('feedback_reports', fn (Blueprint $table) => $table->unique('guest_access_hash'));
        }
        if (!Schema::hasIndex('feedback_reports', ['user_id', 'updated_at'])) {
            Schema::table('feedback_reports', fn (Blueprint $table) => $table->index(['user_id', 'updated_at']));
        }
        if (!Schema::hasIndex('feedback_reports', ['status', 'first_response_due_at'])) {
            Schema::table('feedback_reports', fn (Blueprint $table) => $table->index(['status', 'first_response_due_at']));
        }

        if (!Schema::hasTable('support_case_messages')) {
            Schema::create('support_case_messages', function (Blueprint $table): void {
                $table->id();
                $table->char('public_id', 26)->unique();
                $table->foreignId('feedback_report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('author_type', 16);
                $table->string('visibility', 16)->default('customer');
                $table->text('body');
                $table->uuid('client_request_id')->nullable();
                $table->char('request_fingerprint', 64)->nullable();
                $table->timestamps();

                $table->unique(['feedback_report_id', 'client_request_id'], 'support_message_request_unique');
                $table->index(['feedback_report_id', 'visibility', 'id'], 'support_message_timeline');
            });
        }

        if (!Schema::hasTable('support_case_events')) {
            Schema::create('support_case_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('feedback_report_id')->constrained()->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 32);
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['feedback_report_id', 'id']);
            });
        }

        if (Schema::hasTable('feedback_attachments')) {
            if (!Schema::hasColumn('feedback_attachments', 'support_case_message_id')) {
                Schema::table('feedback_attachments', fn (Blueprint $table) =>
                    $table->foreignId('support_case_message_id')->nullable()
                        ->constrained('support_case_messages')->cascadeOnDelete()
                );
            }
            if (!Schema::hasColumn('feedback_attachments', 'sha256')) {
                Schema::table('feedback_attachments', fn (Blueprint $table) =>
                    $table->char('sha256', 64)->nullable()
                );
            }
            if (!Schema::hasColumn('feedback_attachments', 'scan_status')) {
                Schema::table('feedback_attachments', fn (Blueprint $table) =>
                    $table->string('scan_status', 16)->default('sanitized')
                );
            }
        }
    }

    public function down(): void
    {
        // Additive production migration: rollback keeps case history intact.
    }
};
