<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('video_guid', 64)->unique();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 48);
            $table->timestamp('eligible_after');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('remote_deleted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['reviewed_at', 'eligible_after'], 'bunny_cleanup_review_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bunny_video_cleanup_candidates');
    }
};
