<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProjectSubmissionService;
use Illuminate\Console\Command;

class FinalizePendingProjectSubmissions extends Command
{
    protected $signature = 'projects:finalize-pending {--limit=100}';
    protected $description = 'Finalize due project submissions using the non-blocking effort policy';

    public function handle(ProjectSubmissionService $service): int
    {
        $count = $service->finalizeDue(max(1, (int) $this->option('limit')));
        $this->info("Finalized {$count} project submissions.");

        return self::SUCCESS;
    }
}
