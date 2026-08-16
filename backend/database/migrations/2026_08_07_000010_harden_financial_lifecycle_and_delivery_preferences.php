<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateTransaction = DB::table('orders')
            ->select('transaction_id')
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '<>', '')
            ->groupBy('transaction_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateTransaction) {
            throw new RuntimeException(
                'Duplicate payment transaction IDs must be reconciled before this migration can run.'
            );
        }

        $duplicateBill = DB::table('bills')
            ->select('order_id')
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicateBill) {
            throw new RuntimeException(
                'Duplicate bills for one order must be reconciled before this migration can run.'
            );
        }

        DB::table('orders')->where('transaction_id', '')->update(['transaction_id' => null]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('financial_status', 32)->default('pending')->after('status');
            $table->timestamp('reversed_at')->nullable()->after('approved_at');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->unsignedInteger('recovered_coins')->default(0)->after('reversal_reason');
            $table->unsignedInteger('unrecovered_coins')->default(0)->after('recovered_coins');
            $table->index(['financial_status', 'updated_at'], 'orders_financial_reconciliation');
            $table->unique('transaction_id', 'orders_transaction_id_unique');
        });

        DB::table('orders')->where('status', 'approved')->update(['financial_status' => 'settled']);
        DB::table('orders')->where('status', 'rejected')->update(['financial_status' => 'rejected']);
        DB::table('orders')->where('status', 'cancelled')->update(['financial_status' => 'cancelled']);

        Schema::table('bills', function (Blueprint $table): void {
            $table->unique('order_id', 'bills_order_unique');
        });

        Schema::table('package_user', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->after('package_id');
            $table->unique('order_id', 'package_user_order_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('preferred_locale', 5)->default('ar')->after('notifications_status');
            $table->boolean('leaderboard_opt_in')->default(false)->after('preferred_locale');
            $table->timestamp('last_learning_nudge_at')->nullable()->after('leaderboard_opt_in');
            $table->index(
                ['notifications_status', 'last_learning_nudge_at', 'id'],
                'users_learning_nudge_fairness'
            );
        });

        Schema::create('order_financial_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('event_key', 96);
            $table->string('provider', 32)->nullable();
            $table->string('external_event_id', 191)->nullable();
            $table->unsignedInteger('recovered_coins')->default(0);
            $table->unsignedInteger('unrecovered_coins')->default(0);
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['order_id', 'event_key'], 'order_financial_event_once');
            $table->unique(
                ['provider', 'external_event_id'],
                'order_financial_provider_event_once'
            );
            $table->index(['event_type', 'occurred_at'], 'order_financial_event_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_financial_events');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_learning_nudge_fairness');
            $table->dropColumn(['preferred_locale', 'leaderboard_opt_in', 'last_learning_nudge_at']);
        });

        Schema::table('package_user', function (Blueprint $table): void {
            $table->dropUnique('package_user_order_unique');
            $table->dropColumn('order_id');
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique('bills_order_unique');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_transaction_id_unique');
            $table->dropIndex('orders_financial_reconciliation');
            $table->dropColumn([
                'financial_status', 'reversed_at', 'reversal_reason',
                'recovered_coins', 'unrecovered_coins',
            ]);
        });
    }
};
