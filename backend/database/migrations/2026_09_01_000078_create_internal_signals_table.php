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
        if (!Schema::hasTable('internal_signals')) {
            Schema::create('internal_signals', function (Blueprint $table): void {
                $table->id();
                $table->char('signal_key', 64)->unique();
                $table->string('type', 64)->index();
                $table->string('aggregate_type', 64)->nullable();
                $table->string('aggregate_id', 96)->nullable();
                $table->char('payload_fingerprint', 64);
                $table->json('payload');
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('available_at')->nullable()->index();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->uuid('lease_id')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->char('last_error_fingerprint', 64)->nullable();
                $table->timestamps();

                $table->index(
                    ['status', 'available_at', 'locked_at', 'id'],
                    'internal_signals_recovery'
                );
                $table->index(
                    ['aggregate_type', 'aggregate_id', 'type'],
                    'internal_signals_aggregate'
                );
            });
        }

        if (
            Schema::hasTable('ai_usage_events')
            && !Schema::hasIndex('ai_usage_events', 'ai_usage_completed_period')
        ) {
            Schema::table('ai_usage_events', function (Blueprint $table): void {
                $table->index(
                    ['status', 'completed_at', 'id'],
                    'ai_usage_completed_period'
                );
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('ai_usage_events')
            && Schema::hasIndex('ai_usage_events', 'ai_usage_completed_period')
        ) {
            Schema::table('ai_usage_events', function (Blueprint $table): void {
                $table->dropIndex('ai_usage_completed_period');
            });
        }
        Schema::dropIfExists('internal_signals');
    }
};
