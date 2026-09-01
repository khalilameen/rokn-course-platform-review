<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('course_chat_turns')) {
            return;
        }

        Schema::create('course_chat_turns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('usage_event_id')->nullable();
            $table->uuid('client_request_id');
            $table->char('request_fingerprint', 64);
            $table->char('prompt_version', 40);
            $table->string('language', 12)->default('ar');
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 64)->nullable();
            $table->text('question');
            $table->mediumText('answer')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id'], 'course_chat_turn_user_request_unique');
            $table->index(
                ['user_id', 'course_id', 'lesson_id', 'language', 'prompt_version', 'id'],
                'course_chat_turn_history_index'
            );
            $table->index(['status', 'updated_at'], 'course_chat_turn_status_index');
            $table->index('enrollment_id');
            $table->index('usage_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_chat_turns');
    }
};
