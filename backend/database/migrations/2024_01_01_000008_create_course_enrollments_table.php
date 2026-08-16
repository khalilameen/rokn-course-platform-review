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
        $canReferenceCourses = Schema::hasTable('courses')
            || Schema::getConnection()->getDriverName() === 'sqlite';
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('course_enrollments', function (Blueprint $table) use ($canReferenceCourses, $isSqlite) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('access_granted_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            if ($canReferenceCourses) {
                $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            }
            // The historical MySQL sequence later replaces orders.id, so its
            // enrollment/order constraint must be installed by the release-tail
            // hardening migration after that destructive legacy step. SQLite
            // never performs the replacement and must declare the constraint at
            // CREATE TABLE time because Laravel 9 cannot add it later.
            if ($isSqlite) {
                $table->foreign('order_id')
                    ->references('id')
                    ->on('orders')
                    ->restrictOnDelete()
                    ->restrictOnUpdate();
            }

            // Prevent duplicate enrollments
            $table->unique(['user_id', 'course_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('course_enrollments');
    }
};
