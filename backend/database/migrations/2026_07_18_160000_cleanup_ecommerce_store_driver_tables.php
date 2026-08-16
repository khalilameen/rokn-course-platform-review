<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CleanupEcommerceStoreDriverTables extends Migration
{
    /**
     * Drop all legacy e-commerce, store, driver, and delivery tables.
     */
    public function up()
    {
        $tables = [
            // Order delivery/product tables
            'order_addons',
            'order_details',
            'order_requests',
            'order_notifications',
            // Product & store tables
            'product_addon_details',
            'product_addons',
            'products',
            'item_lists',
            'store_lists',
            'store_info',
            'user_store',          // pivot: driver <-> store
            'store_service',       // pivot: store <-> service
            'service_user',        // pivot: service <-> user
            'stores',
            // Services
            'services',
            // Delivery / logistics
            'delivery_times',
            'branches',
            // Driver / vehicle
            'driver_requests',
            'cars',
            'car_years',
            // Ratings & messages (physical delivery)
            'rates',
            'messages',
            // Legacy statuses
            'statuses',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Reverse the migrations (not supported for destructive cleanup).
     */
    public function down()
    {
        // Intentionally left empty — this cleanup is permanent.
    }
}
