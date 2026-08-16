<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class StoreInfo extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('store_info', function (Blueprint $table) {
            $table->id();
            $table->integer('store_id')->nullable();;
            $table->string('legal_name',255)->nullable();;
            $table->integer('category_id')->nullable();
            $table->string('commercial_name',255)->nullable();;
            $table->string('official_email',255)->nullable();;
            $table->string('city',255)->nullable();;
            $table->string('administrator_name',255)->nullable();;
            $table->string('administrator_phone',255)->nullable();;
            $table->string('administrator_position',255)->nullable();;
            $table->string('administrator_email',255)->nullable();;
            $table->string('commercial_register',255)->nullable();;
            $table->string('bank_account_name',255)->nullable();;
            $table->string('bank_account_owner',255)->nullable();;
            $table->string('bank_iban',255)->nullable();;
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
