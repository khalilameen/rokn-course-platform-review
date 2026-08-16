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
            // SQLite's $table->id() is already an auto-incrementing primary
            // key. Rebuilding it would invalidate inbound order foreign keys.
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Drop the existing id column
            $table->dropColumn('id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Recreate the id column as auto-incrementing primary key
            $table->id()->first();
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
            // Drop the auto-incrementing id column
            $table->dropColumn('id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Recreate the id column as non-auto-incrementing
            $table->unsignedBigInteger('id')->first();
        });
    }
};
