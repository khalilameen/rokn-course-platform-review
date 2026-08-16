<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Unique reference sent to Kashier as merchantOrderId
            $table->string('order_ref')->nullable()->unique()->after('id');

            // Kashier transaction ID returned in callback
            $table->string('transaction_id')->nullable()->after('order_ref');

            // Package being purchased (null for course orders)
            if (!Schema::hasColumn('orders', 'package_id')) {
                $table->unsignedBigInteger('package_id')->nullable()->index()->after('course_id');
            }

            // Full Kashier callback response for auditing
            $table->json('payment_gateway_response')->nullable()->after('payment_screenshot');
        });

        // Change payment_method from ENUM to VARCHAR so we can support
        // 'kashier', 'wallet_coins', and any future methods without migrations.
        // SQLite already represents Laravel's enum as text. Only MySQL needs
        // its native ENUM widened for kashier and wallet_coins values.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT 'online'");
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('package_id')->references('id')->on('packages')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('orders', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['package_id']);
            }
            $table->dropColumn([
                'order_ref',
                'transaction_id',
                'package_id',
                'payment_gateway_response',
            ]);
        });

        // Restore ENUM — only safe if existing data only uses original values
        // Keep VARCHAR on rollback: restoring the legacy ENUM would reject
        // legitimate kashier/wallet_coins history already stored in this table.
    }
};
