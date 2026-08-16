<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsRepeatableToCoinEarningMethodsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('coin_earning_methods', function (Blueprint $table) {
            $table->boolean('is_repeatable')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('coin_earning_methods', function (Blueprint $table) {
            $table->dropColumn('is_repeatable');
        });
    }
}
