<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'checkout_expires_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('checkout_expires_at')
                ->nullable()
                ->after('checkout_request_key');
            $table->index(
                ['status', 'checkout_expires_at'],
                'orders_checkout_expiry_lookup'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('orders', 'checkout_expires_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_checkout_expiry_lookup');
            $table->dropColumn('checkout_expires_at');
        });
    }
};
