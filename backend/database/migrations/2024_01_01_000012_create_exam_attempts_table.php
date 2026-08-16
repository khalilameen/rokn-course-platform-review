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
        $driver = Schema::getConnection()->getDriverName();
        $canReferenceTenants = Schema::hasTable('tenants') || $driver === 'sqlite';
        $canReferenceCourses = Schema::hasTable('courses') || $driver === 'sqlite';
        $canReferenceSections = Schema::hasTable('course_sections') || $driver === 'sqlite';

        Schema::create('exam_attempts', function (Blueprint $table) use (
            $canReferenceTenants,
            $canReferenceCourses,
            $canReferenceSections
        ) {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('quiz_id')->constrained('lists')->onDelete('cascade');
            $table->foreignId('course_id')->nullable();
            $table->foreignId('section_id')->nullable();
            $table->string('attempt_number')->default('1');
            $table->enum('status', ['in_progress', 'completed', 'abandoned'])->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('time_taken_minutes')->nullable();
            $table->integer('total_questions');
            $table->integer('answered_questions')->default(0);
            $table->integer('correct_answers')->default(0);
            $table->decimal('score_percentage', 5, 2)->nullable();
            $table->decimal('score_points', 8, 2)->nullable();
            $table->boolean('is_passed')->default(false);
            $table->json('exam_data')->nullable(); // Store exam questions and correct answers for reference
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['user_id', 'quiz_id']);
            $table->index(['user_id', 'course_id']);
            $table->index(['status', 'started_at']);

            if ($canReferenceTenants) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            }
            if ($canReferenceCourses) {
                $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            }
            if ($canReferenceSections) {
                $table->foreign('section_id')->references('id')->on('course_sections')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
