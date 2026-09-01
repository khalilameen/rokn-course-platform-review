<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StoredFileDeletionService
{
    public function __construct(private readonly StoredFileReferenceService $references)
    {
    }

    public function deleteOrQueue(string $disk, string $path): void
    {
        $disk = trim($disk);
        $path = ltrim(trim($path), '/');
        if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }
        if ($this->references->isReferenced($disk, $path)) {
            return;
        }

        // Never remove bytes while the database transaction that detached
        // them can still roll back. In that case the durable cleanup row and
        // its dispatch are committed together with the reference change.
        if (DB::transactionLevel() === 0) {
            try {
                $storage = Storage::disk($disk);
                if (!$storage->exists($path) || $storage->delete($path)) {
                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $row = AccountFileDeletion::query()->updateOrCreate(
            ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
            [
                'user_id' => null,
                'path' => $path,
                'status' => AccountFileDeletion::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
                'completed_at' => null,
                'last_error' => null,
            ]
        );
        $dispatch = static function () use ($row): void {
            try {
                DeleteAccountFile::dispatch((int) $row->id)->onQueue('default');
            } catch (Throwable $exception) {
                // The row is the durable outbox. The scheduler will dispatch
                // it once the queue connection is healthy again.
                Log::warning('Stored-file cleanup remains pending after dispatch failure.', [
                    'deletion_id' => $row->id,
                    'exception' => $exception::class,
                ]);
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
    }
}
