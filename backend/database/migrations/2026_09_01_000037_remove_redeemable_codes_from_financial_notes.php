<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $hasBills = Schema::hasTable('bills');
        DB::table('orders')
            ->whereNotNull('course_code_id')
            ->select(['id', 'course_code_id'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use ($hasBills): void {
                foreach ($orders as $order) {
                    $note = 'Course code grant #'.(int) $order->course_code_id;
                    DB::table('orders')->where('id', $order->id)->update([
                        'coupon_code' => null,
                        'notes' => $note,
                    ]);
                    if ($hasBills) {
                        DB::table('bills')->where('order_id', $order->id)->update(['notes' => $note]);
                    }
                }
            });

        DB::table('orders')
            ->whereIn('payment_method', ['wallet', 'wallet_coins'])
            ->where('notes', 'like', 'Idempotency:%')
            ->update(['notes' => 'Wallet course purchase']);

        if ($hasBills) {
            DB::table('bills')
                ->where('notes', 'like', 'Paid via Rokn coins with coupon %')
                ->select(['id', 'order_id'])
                ->orderBy('id')
                ->chunkById(500, function ($bills): void {
                    foreach ($bills as $bill) {
                        $couponId = DB::table('orders')
                            ->where('id', $bill->order_id)
                            ->value('coupon_id');
                        DB::table('bills')->where('id', $bill->id)->update([
                            'notes' => $couponId
                                ? 'Paid via Rokn coins with coupon #'.(int) $couponId
                                : 'Paid via Rokn coins',
                        ]);
                    }
                });
        }

        if (Schema::hasTable('wallet_transactions')) {
            DB::table('wallet_transactions')
                ->where('category', 'course_purchase')
                ->whereNotNull('metadata')
                ->select(['id', 'metadata'])
                ->orderBy('id')
                ->chunkById(500, function ($transactions): void {
                    foreach ($transactions as $transaction) {
                        $metadata = json_decode((string) $transaction->metadata, true);
                        if (!is_array($metadata) || !array_key_exists('coupon_code', $metadata)) {
                            continue;
                        }
                        unset($metadata['coupon_code']);
                        $couponId = DB::table('orders')
                            ->where('wallet_transaction_id', $transaction->id)
                            ->value('coupon_id');
                        if ($couponId) {
                            $metadata['coupon_id'] = (int) $couponId;
                        }
                        DB::table('wallet_transactions')
                            ->where('id', $transaction->id)
                            ->update(['metadata' => json_encode(
                                $metadata,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            )]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Raw redeemable codes cannot be reconstructed and must not be copied
        // back into financial notes.
    }
};
