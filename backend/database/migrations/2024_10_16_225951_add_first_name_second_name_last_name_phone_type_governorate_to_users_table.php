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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('second_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('parent_phone')->nullable();
            $table->string('parent_job')->nullable();
            $table->string('type')->nullable();
            $table->string('governorate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('first_name');
            $table->dropColumn('second_name');
            $table->dropColumn('last_name');
            $table->dropColumn('parent_job');
            $table->dropColumn('phone');
            $table->dropColumn('parent_phone');
            $table->dropColumn('type');
            $table->dropColumn('governorate');
        });
    }
};
