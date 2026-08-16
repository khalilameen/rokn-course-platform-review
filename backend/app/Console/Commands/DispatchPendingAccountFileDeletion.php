<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class DispatchPendingAccountFileDeletion extends Command
{
    protected $signature = 'privacy:cleanup-account-files {--limit=500}';
    protected $description = 'Dispatch durable account-file deletion outbox entries';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $rows = AccountFileDeletion::query()
            ->whereIn('status', [AccountFileDeletion::STATUS_PENDING, AccountFileDeletion::STATUS_FAILED])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $dispatched = 0;
        foreach ($rows as $row) {
            try {
                DeleteAccountFile::dispatch((int) $row->id)->onQueue('default');
                $dispatched++;
            } catch (\Throwable $exception) {
                Log::warning('Unable to dispatch account-file cleanup.', [
                    'deletion_id' => $row->id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        $this->info("Queued {$dispatched} account-file cleanup job(s).");
        return self::SUCCESS;
    }
}
