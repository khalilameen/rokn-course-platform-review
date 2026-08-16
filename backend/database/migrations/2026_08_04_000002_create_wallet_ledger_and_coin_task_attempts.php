<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('category', 40);
            $table->unsignedInteger('amount');
            $table->integer('balance_after');
            $table->nullableMorphs('source');
            $table->string('idempotency_key', 140);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'wallet_transaction_idempotency');
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::create('user_coin_task_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coin_earning_method_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('started');
            $table->timestamp('started_at');
            $table->timestamp('claim_available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'coin_earning_method_id'], 'coin_task_once_per_user');
        });

        Schema::table('coin_earning_methods', function (Blueprint $table): void {
            $table->text('action_url')->nullable()->after('action_key');
            $table->boolean('requires_external_visit')->default(false)->after('action_url');
            $table->unsignedSmallInteger('verification_delay_seconds')->default(3)->after('requires_external_visit');
        });
    }

    public function down(): void
    {
        Schema::table('coin_earning_methods', function (Blueprint $table): void {
            $table->dropColumn(['action_url', 'requires_external_visit', 'verification_delay_seconds']);
        });
        Schema::dropIfExists('user_coin_task_attempts');
        Schema::dropIfExists('wallet_transactions');
    }
};
