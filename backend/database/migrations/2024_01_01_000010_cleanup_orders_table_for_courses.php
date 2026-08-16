<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite emulates dropColumn by rebuilding the whole table. That
            // breaks external foreign keys such as order_details.order_id on a
            // clean install. Keep the harmless legacy columns in local/test
            // databases and add the normalized course-order shape in place.
            Schema::table('orders', function (Blueprint $table) {
                $table->decimal('amount', 10, 2)->nullable();
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('final_amount', 10, 2)->default(0);
                $table->string('status')->default('pending');
                $table->text('notes')->nullable();
            });

            DB::table('orders')->update(['amount' => DB::raw('price')]);

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Remove columns not needed for course orders
            $table->dropColumn([
                'provider_id',
                'store_id', 
                'store_name',
                'tax',
                'sub_total',
                'paid',
                'order_note',
                'status_id',
                'type',
                'service_id',
                'client_lat',
                'client_lng',
                'delivering_lat',
                'delivering_lng',
                'coupon_code',
                'discount',
                'payment_type',
                'delivery_time_id',
                'finish_at',
                'cancelled_at'
            ]);
        });

        // Rename price to amount for consistency
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('price', 'amount');
        });

        // Add missing columns for course orders
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('final_amount', 10, 2)->default(0)->after('discount_amount');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending')->after('final_amount');
            $table->text('notes')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Avoid another SQLite table rebuild with inbound foreign keys.
            // Test databases are disposable and migrate:fresh owns cleanup.
            return;
        }

        // Add back the removed columns
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->string('store_name')->nullable();
            $table->double('tax', 8, 2)->default(0);
            $table->double('sub_total', 8, 2)->default(0);
            $table->boolean('paid')->default(false);
            $table->text('order_note')->nullable();
            $table->integer('status_id')->default(1);
            $table->enum('type', ['product', 'service', 'general'])->default('general');
            $table->integer('service_id')->nullable();
            $table->string('client_lat')->nullable();
            $table->string('client_lng')->nullable();
            $table->string('delivering_lat')->nullable();
            $table->string('delivering_lng')->nullable();
            $table->string('coupon_code')->nullable();
            $table->double('discount', 8, 2)->default(0);
            $table->enum('payment_type', ['cash_on_delivery', 'apple_pay'])->nullable();
            $table->integer('delivery_time_id')->nullable();
            $table->datetime('finish_at')->nullable();
            $table->datetime('cancelled_at')->nullable();
        });

        // Remove the added columns
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'final_amount', 'status', 'notes']);
        });

        // Rename amount back to price
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('amount', 'price');
        });
    }
};
