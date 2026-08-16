<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PurgeExpiredApiTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [60, 300];

    public function handle(): void
    {
        $table = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($table)) {
            return;
        }

        do {
            $tokens = DB::table($table)
                ->whereNotNull('expired_at')
                ->where('expired_at', '<', now())
                ->orderBy('expired_at')
                ->limit(1000)
                ->pluck('token');

            if ($tokens->isNotEmpty()) {
                DB::table($table)->whereIn('token', $tokens)->delete();
            }
        } while ($tokens->count() === 1000);
    }
}
