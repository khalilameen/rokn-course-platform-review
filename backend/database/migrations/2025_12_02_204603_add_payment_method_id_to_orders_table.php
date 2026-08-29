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
            // SQLite stores Laravel enums as text already, so only the new
            // optional reference is required. Avoid rebuilding orders.
            if (! Schema::hasColumn('orders', 'payment_method_id')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->unsignedBigInteger('payment_method_id')->nullable();
                });
            }

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Drop and recreate payment_method column to change from ENUM to VARCHAR
            if (Schema::hasColumn('orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
        
        Schema::table('orders', function (Blueprint $table) {
            // Add payment_method as VARCHAR
            $table->string('payment_method', 50)->default('course_code');
            
            // Add payment_method_id column
            if (!Schema::hasColumn('orders', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->nullable()->after('payment_method');
                $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('set null');
            }
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
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_method_id')) {
                $table->dropForeign(['payment_method_id']);
                $table->dropColumn('payment_method_id');
            }
            
            // Drop VARCHAR payment_method
            if (Schema::hasColumn('orders', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
        
        // Restore ENUM payment_method
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_method', ['online', 'course_code', 'wallet'])->default('online');
        });
    }
};
