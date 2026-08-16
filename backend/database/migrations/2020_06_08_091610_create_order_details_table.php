<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrderDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('service_name')->nullable();
            $table->string('product_name')->nullable();
            $table->string('store_name')->nullable();
            $table->float('price');
            $table->integer('quantity');
            $table->float('total_price');
            $table->timestamps();
           
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->onDelete('cascade'); 
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('set null'); 
            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->onDelete('set null');    
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('set null');                                                  
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('order_details');
    }
}
