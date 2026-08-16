<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class OperationsReadinessService
{
    /** @return array<string, mixed>|null */
    public function mediaReconcileStatus(): ?array
    {
        try {
            $value = Cache::get((string) config(
                'operations.media_reconcile_status_key',
                'operations:media-reconcile:status:v1'
            ));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    public function backupReadiness(): array
    {
        $runbookPath = base_path('PRODUCTION_RUNBOOK.md');
        $provider = trim((string) config('operations.backup_provider'));
        $lastBackup = $this->parseDate(config('operations.backup_last_verified_at'));
        $lastRestoreDrill = $this->parseDate(config('operations.restore_drill_verified_at'));
        $backupMaximumAge = max(1, (int) config('operations.backup_max_age_hours', 26));
        $drillMaximumAge = max(1, (int) config('operations.restore_drill_max_age_days', 90));

        $checks = [
            'runbook' => is_file($runbookPath),
            'provider' => $provider !== '',
            'recent_backup' => $lastBackup?->gte(now()->subHours($backupMaximumAge)) ?? false,
            'recent_restore_drill' => $lastRestoreDrill?->gte(now()->subDays($drillMaximumAge)) ?? false,
        ];

        return [
            'ready' => !in_array(false, $checks, true),
            'checks' => $checks,
            'provider' => $provider !== '' ? $provider : null,
            'last_backup_at' => $lastBackup,
            'last_restore_drill_at' => $lastRestoreDrill,
            'runbook' => is_file($runbookPath) ? 'PRODUCTION_RUNBOOK.md' : null,
            'note' => 'Read-only evidence. The dashboard never starts a backup or restore.',
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
