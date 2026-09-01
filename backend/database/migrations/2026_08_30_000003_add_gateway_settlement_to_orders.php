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
        if (!Schema::hasTable('orders')) return;
        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'gateway_gross_amount')) $table->decimal('gateway_gross_amount', 12, 2)->nullable()->after('final_amount');
            if (!Schema::hasColumn('orders', 'gateway_fee_amount')) $table->decimal('gateway_fee_amount', 12, 2)->nullable()->after('gateway_gross_amount');
            if (!Schema::hasColumn('orders', 'gateway_net_amount')) $table->decimal('gateway_net_amount', 12, 2)->nullable()->after('gateway_fee_amount');
            if (!Schema::hasColumn('orders', 'gateway_currency')) $table->string('gateway_currency', 3)->nullable()->after('gateway_net_amount');
            if (!Schema::hasColumn('orders', 'gateway_settlement_status')) $table->string('gateway_settlement_status', 32)->nullable()->after('gateway_currency');
            if (!Schema::hasColumn('orders', 'gateway_settled_at')) $table->timestamp('gateway_settled_at')->nullable()->after('gateway_settlement_status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) return;
        $columns = array_values(array_filter([
            'gateway_gross_amount', 'gateway_fee_amount', 'gateway_net_amount',
            'gateway_currency', 'gateway_settlement_status', 'gateway_settled_at',
        ], static fn (string $column): bool => Schema::hasColumn('orders', $column)));
        if ($columns !== []) Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
