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
            $table->decimal('gateway_gross_amount', 12, 2)->nullable()->after('final_amount');
            $table->decimal('gateway_fee_amount', 12, 2)->nullable()->after('gateway_gross_amount');
            $table->decimal('gateway_net_amount', 12, 2)->nullable()->after('gateway_fee_amount');
            $table->string('gateway_currency', 3)->nullable()->after('gateway_net_amount');
            $table->string('gateway_settlement_status', 32)->nullable()->after('gateway_currency');
            $table->timestamp('gateway_settled_at')->nullable()->after('gateway_settlement_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'gateway_gross_amount',
                'gateway_fee_amount',
                'gateway_net_amount',
                'gateway_currency',
                'gateway_settlement_status',
                'gateway_settled_at',
            ]);
        });
    }
};
