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
        Schema::table('orders', function (Blueprint $table) {
            // Add course-related fields
            $table->unsignedBigInteger('course_id')->nullable()->index()->after('user_id');
            $table->unsignedBigInteger('course_code_id')->nullable()->index()->after('course_id');
            $table->enum('payment_method', ['online', 'course_code', 'wallet'])->default('online')->after('coupon_code');
            $table->string('payment_screenshot')->nullable()->after('payment_method');
            $table->timestamp('approved_at')->nullable()->after('cancelled_at');
            $table->unsignedBigInteger('approved_by')->nullable()->index()->after('approved_at');
        });

        // Add foreign key constraints. The historical clean-install order
        // creates courses later, so MySQL must defer that one relationship.
        // This is an ALTER TABLE migration. Unlike CREATE TABLE, SQLite has
        // to rebuild orders to add a foreign key, which can invalidate inbound
        // legacy references. The release-tail repair handles MySQL; disposable
        // SQLite databases keep the indexed course_id column without a rebuild.
        $canReferenceCourses = Schema::hasTable('courses');

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('course_code_id')->references('id')->on('course_codes')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });

        if ($canReferenceCourses) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropForeign(['course_code_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['course_id', 'course_code_id', 'payment_method', 'payment_screenshot', 'approved_at', 'approved_by']);
        });
    }
};
