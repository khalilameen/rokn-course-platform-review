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
        if (! Schema::hasTable('payment_reconciliation_checkpoints')) {
            Schema::create('payment_reconciliation_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->unique();
            $table->unsignedBigInteger('cursor_order_id')->default(0);
            $table->unsignedInteger('cycles')->default(0);
            $table->unsignedInteger('last_batch_size')->default(0);
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_reconciliation_findings')) {
            Schema::create('payment_reconciliation_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_ref', 191);
            $table->char('fingerprint', 64)->unique();
            $table->string('kind', 64);
            $table->string('local_status', 32)->nullable();
            $table->string('local_financial_status', 32)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('state', 16)->default('open');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['provider', 'state', 'last_seen_at'], 'payment_reconcile_provider_state_seen');
            $table->index(['order_id', 'state'], 'payment_reconcile_order_state');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_findings');
        Schema::dropIfExists('payment_reconciliation_checkpoints');
    }
};
