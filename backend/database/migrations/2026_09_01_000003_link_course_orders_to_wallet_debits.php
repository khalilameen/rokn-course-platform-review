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
        if (!Schema::hasTable('orders') || !Schema::hasTable('wallet_transactions')) {
            throw new RuntimeException('Orders and wallet transactions must exist before linking wallet debits.');
        }

        if (!Schema::hasColumn('orders', 'wallet_transaction_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('wallet_transaction_id')
                    ->nullable()
                    ->after('checkout_request_key')
                    ->constrained('wallet_transactions')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'wallet_transaction_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('wallet_transaction_id');
            });
        }
    }
};
