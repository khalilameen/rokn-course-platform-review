<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_credit_lots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('credit_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->unsignedInteger('original_amount');
            $table->unsignedInteger('remaining_amount');
            $table->unsignedInteger('recovered_amount')->default(0);
            $table->string('status', 24)->default('active');
            $table->timestamp('credited_at');
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('credit_transaction_id', 'wallet_credit_lot_transaction_unique');
            $table->unique('source_order_id', 'wallet_credit_lot_source_order_unique');
            $table->index(
                ['user_id', 'status', 'credited_at', 'id'],
                'wallet_credit_lots_fifo'
            );
        });

        Schema::create('wallet_debit_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('credit_lot_id')->constrained('wallet_credit_lots')->restrictOnDelete();
            $table->foreignId('course_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('entitlement_scope', 16)->default('course');
            $table->timestamp('allocated_at');
            $table->timestamps();

            $table->unique(
                ['wallet_transaction_id', 'credit_lot_id'],
                'wallet_debit_allocation_once'
            );
            $table->index(
                ['credit_lot_id', 'course_order_id'],
                'wallet_allocations_reversal_lookup'
            );
        });

        Schema::create('financial_entitlement_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('source_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
            $table->timestamp('enrollment_deactivated_at')->nullable();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->timestamp('certificate_revoked_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->string('entitlement_scope', 16)->default('course');
            $table->string('reason', 255)->nullable();
            $table->string('resolution', 24)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('held_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_order_id', 'course_order_id'],
                'financial_entitlement_hold_once'
            );
            $table->index(
                ['user_id', 'course_id', 'status'],
                'financial_entitlement_access_lookup'
            );
            $table->index(
                ['source_order_id', 'status'],
                'financial_entitlement_resolution_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_entitlement_holds');
        Schema::dropIfExists('wallet_debit_allocations');
        Schema::dropIfExists('wallet_credit_lots');
    }
};
