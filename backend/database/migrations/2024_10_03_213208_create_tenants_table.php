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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name'); //
            $table->string('phone')->nullable(); // 'phone' field
            $table->string('whatsapp')->nullable(); // 'whatsapp' field
            $table->decimal('latitude', 10, 8)->nullable(); // 'latitude' field with precision
            $table->decimal('longitude', 11, 8)->nullable(); // 'longitude' field with precision
            $table->string('map_address')->nullable(); // 'map_address' field
            $table->string('image')->nullable(); // 'image' field
            $table->softDeletes(); // 'deleted_at' field
            $table->timestamps(); // 'created_at' and 'updated_at' fields
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('tenants');
    }
};
