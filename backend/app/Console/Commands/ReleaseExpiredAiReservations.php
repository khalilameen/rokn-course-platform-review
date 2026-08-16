<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AiEntitlementBudgetService;
use Illuminate\Console\Command;

final class ReleaseExpiredAiReservations extends Command
{
    protected $signature = 'ai:release-expired-reservations {--limit=500}';

    protected $description = 'Release AI entitlement reservations abandoned by killed requests or workers';

    public function handle(AiEntitlementBudgetService $budget): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $released = $budget->releaseExpiredReservations($limit);
        $this->info("Released {$released} expired AI reservations.");

        return self::SUCCESS;
    }
}
