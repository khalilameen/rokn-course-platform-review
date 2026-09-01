<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('financial_anomalies')) {
            return;
        }

        Schema::create('financial_anomalies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('course_enrollments')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 48);
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('expected_paid_coins');
            $table->unsignedInteger('actual_paid_coins');
            $table->json('metadata')->nullable();
            $table->timestamp('detected_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type'], 'financial_anomaly_order_once');
            $table->index(['user_id', 'course_id', 'status'], 'financial_anomaly_entitlement_lookup');
            $table->index(['status', 'detected_at'], 'financial_anomaly_operations_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_anomalies');
    }
};
