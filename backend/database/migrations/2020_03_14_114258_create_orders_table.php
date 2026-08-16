<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('store_name')->nullable();
            $table->float('price')->nullable();
            $table->float('tax')->nullable();
            $table->float('sub_total')->nullable();
            $table->boolean('paid')->nullable();
            $table->text('order_note')->nullable();
            $table->integer('status_id')->default(1);
            $table->enum('type', ['product', 'service', 'general'])->default('general');;
            // These legacy fields are removed by the later course-order
            // normalization migration. Keeping them in the historical base
            // schema makes a clean migration sequence internally consistent.
            $table->integer('service_id')->nullable();
           
            $table->string('client_lat')->nullable();
            $table->string('client_lng')->nullable();
            $table->string('delivering_lat')->nullable();
            $table->string('delivering_lng')->nullable();            
            $table->unsignedBigInteger('coupon_id')->nullable(); 
            $table->string('coupon_code')->nullable(); 
            $table->float('discount')->nullable(); 
            $table->float('total')->nullable();   
            $table->enum('payment_type', ['cash_on_delivery', 'apple_pay'])->nullable();
            $table->integer('delivery_time_id')->nullable();
            $table->dateTime('finish_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();         

                                           
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('provider_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null'); 

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');  
            $table->foreign('coupon_id')
                ->references('id')
                ->on('coupons')
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
        Schema::dropIfExists('orders');
    }
}
