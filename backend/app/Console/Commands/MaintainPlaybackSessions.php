<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlaybackMetricsService;
use Illuminate\Console\Command;

final class MaintainPlaybackSessions extends Command
{
    protected $signature = 'playback:maintain
        {--stale-minutes= : Close sessions idle for this many minutes}
        {--limit=2000 : Maximum sessions to close and roll up per run}';

    protected $description = 'Close stale playback sessions and aggregate privacy-safe playback metrics.';

    public function handle(PlaybackMetricsService $metrics): int
    {
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $staleMinutes = $this->option('stale-minutes');
        $closed = $metrics->finalizeStaleSessions(
            $staleMinutes !== null ? (int) $staleMinutes : null,
            $limit
        );
        $rolledUp = $metrics->rollupEndedSessions($limit);

        $this->info("closed={$closed} rolled_up={$rolledUp}");

        return self::SUCCESS;
    }
}
