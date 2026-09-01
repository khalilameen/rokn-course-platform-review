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
        if (!Schema::hasTable('operational_incidents')) {
            Schema::create('operational_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 96)->unique();
            $table->string('category', 32)->index();
            $table->string('severity', 16)->index();
            $table->string('status', 16)->default('open')->index();
            $table->string('summary', 190);
            $table->unsignedBigInteger('affected_count')->default(0);
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('last_alerted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            });
        }
        if (
            Schema::hasTable('outbox_events')
            && !Schema::hasIndex('outbox_events', 'outbox_aggregate_delivery_order')
        ) {
            Schema::table('outbox_events', function (Blueprint $table): void {
                $table->index(
                    ['aggregate_type', 'aggregate_id', 'status', 'id'],
                    'outbox_aggregate_delivery_order'
                );
            });
        }
        if (Schema::hasTable('student_notifications')) {
            $columns = [
                'push_attempts' => fn (Blueprint $table) => $table->unsignedSmallInteger('push_attempts')->default(0)->after('push_attempted_at'),
                'push_failed_at' => fn (Blueprint $table) => $table->timestamp('push_failed_at')->nullable()->after('push_sent_at'),
                'push_failure_code' => fn (Blueprint $table) => $table->string('push_failure_code', 64)->nullable()->after('push_failed_at'),
            ];
            foreach ($columns as $column => $definition) {
                if (!Schema::hasColumn('student_notifications', $column)) {
                    Schema::table('student_notifications', $definition);
                }
            }
            if (!Schema::hasIndex('student_notifications', 'student_push_dead_letters')) {
                Schema::table('student_notifications', fn (Blueprint $table) =>
                    $table->index(['push_failed_at', 'push_sent_at'], 'student_push_dead_letters')
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_notifications')) {
            Schema::table('student_notifications', function (Blueprint $table): void {
                $table->dropIndex('student_push_dead_letters');
                $table->dropColumn(['push_attempts', 'push_failed_at', 'push_failure_code']);
            });
        }
        if (Schema::hasTable('outbox_events')) {
            Schema::table('outbox_events', function (Blueprint $table): void {
                $table->dropIndex('outbox_aggregate_delivery_order');
            });
        }
        Schema::dropIfExists('operational_incidents');
    }
};
