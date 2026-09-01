<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MigrateProductionRelease extends Command
{
    protected $signature = 'rokn:release-migrate
        {--lock-timeout=15 : Maximum seconds to wait for a MySQL metadata or row lock}';

    protected $description = 'Apply one resumable production migration tail and verify its release schema';

    public function handle(): int
    {
        $lockTimeout = filter_var(
            $this->option('lock-timeout'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 300]]
        );
        if ($lockTimeout === false) {
            $this->error('The lock timeout must be an integer from 1 to 300 seconds.');

            return self::INVALID;
        }

        try {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                // Fail a busy release quickly instead of leaving the deploy
                // hung behind an old request holding a metadata lock.
                DB::statement('SET SESSION lock_wait_timeout = '.(int) $lockTimeout);
                DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $lockTimeout);
            }

            $migrationStatus = $this->call('migrate', [
                '--isolated' => true,
                '--force' => true,
                '--no-interaction' => true,
            ]);
            if ($migrationStatus !== self::SUCCESS) {
                return $migrationStatus;
            }

            return $this->call('rokn:preflight', [
                '--schema-only' => true,
                '--allow-mixed-release' => true,
                '--connectivity' => true,
                '--no-interaction' => true,
            ]);
        } catch (Throwable $exception) {
            $this->error('Release migration stopped safely: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
