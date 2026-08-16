<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('exam_attempt_id')->constrained('exam_attempts')->onDelete('cascade');
            $table->string('event_type');
            $table->json('details')->nullable();
            $table->timestamp('timestamp');
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_security_logs');
    }
};
