<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ai_conversation_contexts')) {
            Schema::create('ai_conversation_contexts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('course_id')->constrained()->cascadeOnDelete();
                $table->string('scope', 32);
                $table->string('scope_key', 80);
                $table->unsignedBigInteger('covered_through_id')->default(0);
                // Structured extractive archive. Entitlements can legitimately
                // exceed MySQL TEXT's 64KB once Arabic UTF-8 is included.
                $table->mediumText('summary')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->unique(
                    ['user_id', 'course_id', 'scope', 'scope_key'],
                    'ai_context_owner_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_contexts');
    }
};
