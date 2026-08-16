<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $canReferenceTenants = Schema::hasTable('tenants')
            || Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('exam_answers', function (Blueprint $table) use ($canReferenceTenants) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('exam_attempt_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('question_id');
            $table->integer('selected_answer'); // 1, 2, 3, 4, 5, 6
            $table->boolean('is_correct')->nullable();
            $table->integer('points_earned')->default(0);
            $table->integer('max_points')->default(10);
            $table->timestamp('answered_at');
            $table->json('question_data')->nullable(); // Store question data at time of answering
            $table->timestamps();

            // Indexes
            $table->index(['exam_attempt_id', 'question_id']);
            $table->index(['exam_attempt_id', 'is_correct']);

            if ($canReferenceTenants) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
