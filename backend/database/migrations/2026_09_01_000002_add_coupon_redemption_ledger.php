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
        if (!Schema::hasTable('coupons')) {
            throw new RuntimeException('The coupons table must exist before creating the redemption ledger.');
        }

        if (!Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('coupon_redemptions')) {
            Schema::create('coupon_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
                $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
                $table->string('coupon_code', 50);
                $table->unsignedTinyInteger('discount_percentage');
                $table->unsignedInteger('discount_coins');
                $table->timestamp('redeemed_at');
                $table->timestamps();

                // A campaign coupon is a one-time acquisition benefit for an
                // account. The order row separately preserves the receipt.
                $table->unique(['coupon_id', 'user_id'], 'coupon_user_once');
                $table->index(['user_id', 'redeemed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');

        if (Schema::hasTable('coupons') && Schema::hasColumn('coupons', 'deleted_at')) {
            Schema::table('coupons', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
