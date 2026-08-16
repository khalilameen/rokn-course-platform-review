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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('group_id')->constrained()->onDelete('cascade');
            $table->foreignId('appointment_id')->nullable()->constrained()->onDelete('set null');
            $table->date('attendance_date');
            $table->time('check_in_time')->nullable();
            $table->enum('status', ['present', 'absent', 'late'])->default('present');
            $table->enum('method', ['manual', 'qr_code'])->default('manual');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Unique constraint to prevent duplicate attendance for same student on same date
            $table->unique(['user_id', 'group_id', 'appointment_id', 'attendance_date'], 'unique_daily_attendance');

            // Foreign key constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('attendances');
    }
};
