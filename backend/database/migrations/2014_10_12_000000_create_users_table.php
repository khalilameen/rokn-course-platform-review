<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable()->unique();
            $table->string('social_provider')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->enum('role', ['admin', 'client', 'provider','merchant']);
            $table->enum('gender', ['male', 'female', 'other'])->default('male');
            $table->date('birthday')->nullable();
            $table->integer('rate')->default(0);
            $table->float('balance')->default(0);
            $table->string('api_token', 100)->unique()->nullable()->default(null);
            $table->enum('device_os', ['android', 'ios'])->nullable();
            $table->string('access_token')->nullable()->unique();
            $table->string('notifications_status')->nullable()->default(1);
            $table->boolean('active')->default(false);
            $table->boolean('provider_request')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
