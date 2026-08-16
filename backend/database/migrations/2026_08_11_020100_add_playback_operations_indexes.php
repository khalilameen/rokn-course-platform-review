<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('playback_sessions')) {
            return;
        }

        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->index(
                ['ended_at', 'last_heartbeat_at', 'started_at'],
                'playback_sessions_live_operations'
            );
            $table->index(
                ['started_at', 'last_error_code', 'lesson_id'],
                'playback_sessions_error_operations'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('playback_sessions')) {
            return;
        }

        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->dropIndex('playback_sessions_live_operations');
            $table->dropIndex('playback_sessions_error_operations');
        });
    }
};
