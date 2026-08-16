<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('package_coins')->nullable()->after('package_id');
            $table->string('checkout_request_key', 140)->nullable()->after('order_ref');
            $table->unique(
                ['user_id', 'checkout_request_key'],
                'orders_user_checkout_request_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_user_checkout_request_unique');
            $table->dropColumn(['checkout_request_key', 'package_coins']);
        });
    }
};
