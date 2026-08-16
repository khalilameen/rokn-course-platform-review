<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchQueueHeartbeats extends Command
{
    protected $signature = 'ops:dispatch-queue-heartbeats';

    protected $description = 'Dispatch an independent worker heartbeat to every required production queue';

    public function handle(): int
    {
        $queues = RecordQueueHeartbeat::requiredQueues();
        if ($queues === []) {
            $this->error('No required heartbeat queues are configured.');

            return self::FAILURE;
        }

        $failed = [];
        foreach ($queues as $queue) {
            try {
                RecordQueueHeartbeat::dispatch($queue);
            } catch (Throwable $exception) {
                $failed[] = $queue;
                Log::warning('Unable to dispatch queue heartbeat.', [
                    'queue' => $queue,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($failed !== []) {
            $this->error('Heartbeat dispatch failed for: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
