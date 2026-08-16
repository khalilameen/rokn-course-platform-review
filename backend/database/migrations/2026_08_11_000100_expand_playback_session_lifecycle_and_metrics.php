<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->string('client_name', 24)->nullable()->after('source_host');
            $table->string('app_version', 32)->nullable()->after('client_name');
            $table->string('os_family', 12)->default('other')->after('app_version');
            $table->string('os_version', 32)->nullable()->after('os_family');
            $table->string('connection_type', 12)->default('unknown')->after('os_version');
            $table->json('client_capabilities')->nullable()->after('connection_type');
            $table->string('playback_reason', 48)->nullable()->after('client_capabilities');
            $table->timestamp('source_expires_at')->nullable()->after('playback_reason');
            $table->timestamp('started_playing_at')->nullable()->after('started_at');
            $table->unsignedInteger('startup_latency_ms')->nullable()->after('started_playing_at');
            $table->unsignedSmallInteger('buffer_count')->default(0)->after('startup_latency_ms');
            $table->unsignedInteger('buffer_duration_ms')->default(0)->after('buffer_count');
            $table->unsignedInteger('effective_bitrate_kbps')->nullable()->after('effective_quality');
            $table->timestamp('metrics_rolled_up_at')->nullable()->after('ended_at');

            $table->index(['ended_at', 'metrics_rolled_up_at', 'id'], 'playback_sessions_rollup_queue');
            $table->index(['lesson_id', 'started_at', 'id'], 'playback_sessions_lesson_metrics');
            $table->index(['last_error_code', 'started_at', 'id'], 'playback_sessions_error_metrics');
        });

        Schema::create('playback_metric_rollups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_start');
            // Deliberately not a foreign key: historical aggregates survive
            // content deletion and contain no student-level identifier.
            $table->unsignedBigInteger('lesson_id')->default(0);
            $table->string('os_family', 12)->default('other');
            $table->string('connection_type', 12)->default('unknown');
            $table->string('effective_quality', 12)->default('unknown');
            $table->string('playback_reason', 48)->default('unknown');
            $table->string('error_code', 64)->default('none');
            $table->unsignedBigInteger('session_count')->default(0);
            $table->unsignedBigInteger('completed_count')->default(0);
            $table->unsignedBigInteger('error_session_count')->default(0);
            $table->unsignedBigInteger('buffering_session_count')->default(0);
            $table->unsignedBigInteger('startup_sample_count')->default(0);
            $table->unsignedBigInteger('startup_latency_total_ms')->default(0);
            $table->unsignedInteger('startup_latency_max_ms')->default(0);
            $table->unsignedBigInteger('buffer_event_count')->default(0);
            $table->unsignedBigInteger('buffer_duration_total_ms')->default(0);
            $table->unsignedBigInteger('recovery_total')->default(0);
            $table->unsignedBigInteger('bitrate_sample_count')->default(0);
            $table->unsignedBigInteger('bitrate_total_kbps')->default(0);
            $table->timestamps();

            $table->unique(
                ['bucket_start', 'lesson_id', 'os_family', 'connection_type', 'effective_quality', 'playback_reason', 'error_code'],
                'playback_metric_rollup_dimensions'
            );
            $table->index(['bucket_start', 'lesson_id'], 'playback_metric_rollup_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_metric_rollups');

        Schema::table('playback_sessions', function (Blueprint $table): void {
            $table->dropIndex('playback_sessions_rollup_queue');
            $table->dropIndex('playback_sessions_lesson_metrics');
            $table->dropIndex('playback_sessions_error_metrics');
            $table->dropColumn([
                'client_name', 'app_version', 'os_family', 'os_version',
                'connection_type', 'client_capabilities', 'playback_reason',
                'source_expires_at', 'started_playing_at', 'startup_latency_ms',
                'buffer_count', 'buffer_duration_ms', 'effective_bitrate_kbps',
                'metrics_rolled_up_at',
            ]);
        });
    }
};
