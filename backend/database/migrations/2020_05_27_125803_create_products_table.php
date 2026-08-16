<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar')->nullable();;
            $table->string('name_en')->nullable();;
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('list_id')->nullable();
            $table->float('price')->nullable();;
            $table->float('tax')->nullable();;
            $table->string('lng')->nullable();;
            $table->string('image')->nullable();;
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
        Schema::dropIfExists('products');
    }
}
