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
        // The legacy application migration already creates this table. Never
        // replace an existing orders table containing real payment history.
        if (Schema::hasTable('orders')) {
            return;
        }

        $canReferenceCourses = Schema::hasTable('courses')
            || Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('orders', function (Blueprint $table) use ($canReferenceCourses) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('course_code_id')->nullable()->index();
            $table->unsignedBigInteger('coupon_id')->nullable()->index();
            $table->enum('payment_method', ['online', 'course_code', 'wallet'])->default('online');
            $table->string('payment_screenshot')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            if ($canReferenceCourses) {
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            }
            $table->foreign('course_code_id')->references('id')->on('course_codes')->onDelete('set null');
            $table->foreign('coupon_id')->references('id')->on('coupons')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Intentionally non-destructive: this migration can be skipped because
        // the legacy orders migration owns the existing table.
    }
};
