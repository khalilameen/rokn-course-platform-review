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
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('wallet_purchased_coins')->default(0)->after('wallet_coins');
            $table->unsignedInteger('wallet_reward_coins')->default(0)->after('wallet_purchased_coins');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('bucket', 24)->default('legacy_reward')->after('category');
            $table->unsignedInteger('paid_amount')->default(0)->after('amount');
            $table->unsignedInteger('reward_amount')->default(0)->after('paid_amount');
            $table->unsignedInteger('paid_balance_after')->default(0)->after('balance_after');
            $table->unsignedInteger('reward_balance_after')->default(0)->after('paid_balance_after');
            $table->index(['user_id', 'bucket', 'occurred_at'], 'wallet_transactions_bucket_timeline');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Immutable attribution for course unlocks. These are virtual coin
            // units, deliberately separate from Kashier cash revenue.
            $table->unsignedInteger('total_coins')->nullable()->after('final_amount');
            $table->unsignedInteger('paid_coins')->nullable()->after('total_coins');
            $table->unsignedInteger('reward_coins')->nullable()->after('paid_coins');
            $table->index(
                ['course_id', 'status', 'payment_method'],
                'orders_course_coin_allocation_lookup'
            );
        });

        // Existing balances have no trustworthy origin. Classifying every
        // legacy coin as reward prevents fabricated paid-course revenue.
        DB::table('users')->update([
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => DB::raw('CASE WHEN wallet_coins > 0 THEN wallet_coins ELSE 0 END'),
        ]);
        DB::table('wallet_transactions')->update([
            'bucket' => 'legacy_reward',
            'paid_amount' => 0,
            'reward_amount' => DB::raw('amount'),
            'paid_balance_after' => 0,
            'reward_balance_after' => DB::raw('CASE WHEN balance_after > 0 THEN balance_after ELSE 0 END'),
        ]);

        DB::table('orders')
            ->whereNotNull('course_id')
            ->where('status', 'approved')
            ->whereIn('payment_method', ['wallet', 'wallet_coins'])
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                foreach ($orders as $order) {
                    $coins = max(0, (int) round((float) $order->final_amount));
                    DB::table('orders')->where('id', $order->id)->update([
                        'total_coins' => $coins,
                        'paid_coins' => 0,
                        'reward_coins' => $coins,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_course_coin_allocation_lookup');
            $table->dropColumn(['total_coins', 'paid_coins', 'reward_coins']);
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('wallet_transactions_bucket_timeline');
            $table->dropColumn([
                'bucket', 'paid_amount', 'reward_amount',
                'paid_balance_after', 'reward_balance_after',
            ]);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['wallet_purchased_coins', 'wallet_reward_coins']);
        });
    }
};
