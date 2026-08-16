<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->boolean('requires_review')->default(true)->after('last_error');
            $table->unsignedSmallInteger('attempts')->default(0)->after('requires_review');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->index(['remote_deleted_at', 'eligible_after'], 'bunny_cleanup_due_queue');
        });
    }

    public function down(): void
    {
        Schema::table('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->dropIndex('bunny_cleanup_due_queue');
            $table->dropColumn(['requires_review', 'attempts', 'last_attempt_at']);
        });
    }
};
