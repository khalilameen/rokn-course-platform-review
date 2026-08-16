<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('bills', function (Blueprint $table) {
            // Drop and recreate payment_method column to change from ENUM to VARCHAR
            if (Schema::hasColumn('bills', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            // Add payment_method as VARCHAR
            $table->string('payment_method', 100)->default('online')->after('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('bills', function (Blueprint $table) {
            // Drop VARCHAR payment_method
            if (Schema::hasColumn('bills', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });

        // Restore ENUM payment_method
        Schema::table('bills', function (Blueprint $table) {
            $table->enum('payment_method', ['online', 'course_code', 'wallet'])->default('online')->after('payment_status');
        });
    }
};
