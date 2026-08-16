<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class VerifyRestoreDrill extends Command
{
    protected $signature = 'ops:verify-restore
        {--dump= : Absolute path to a .sql or .sql.gz backup}
        {--database= : Disposable MySQL database name, beginning rokn_restore_verify_}
        {--evidence= : Absolute JSON evidence path (default: storage/app/restore-drills)}
        {--keep : Retain the disposable verification database for investigation}
        {--confirm= : Required exact acknowledgement: RESTORE_<database>}';

    protected $description = 'Restore a MySQL backup into a disposable database and emit privacy-safe machine evidence';

    public function handle(): int
    {
        $dump = (string) $this->option('dump');
        $database = (string) $this->option('database');
        $confirmation = (string) $this->option('confirm');
        if ($dump === '' || !is_file($dump) || !is_readable($dump)) {
            return $this->failVerification('Provide a readable absolute --dump path.');
        }
        if (!preg_match('/\Arokn_restore_verify_[a-z0-9_]+\z/', $database)) {
            return $this->failVerification('--database must be a disposable name beginning rokn_restore_verify_.');
        }
        if ($confirmation !== 'RESTORE_'.$database) {
            return $this->failVerification('Confirmation must be exactly RESTORE_'.$database.'.');
        }
        if ($database === (string) config('database.connections.mysql.database')) {
            return $this->failVerification('Refusing to restore over the configured primary database.');
        }
        if (!preg_match('/\.(?:sql|sql\.gz)\z/i', $dump)) {
            return $this->failVerification('Backup must end in .sql or .sql.gz.');
        }

        $connection = (array) config('database.connections.mysql');
        $binary = trim((string) env('MYSQL_BINARY', 'mysql'));
        $evidencePath = (string) ($this->option('evidence') ?: storage_path('app/restore-drills/'.gmdate('Ymd-His').'.json'));
        try {
            $this->mysql($binary, $connection, ['-e', 'DROP DATABASE IF EXISTS `'.$database.'`; CREATE DATABASE `'.$database.'`']);
            $restore = str_ends_with(strtolower($dump), '.gz')
                ? 'gzip -dc '.escapeshellarg($dump).' | '
                : 'cat '.escapeshellarg($dump).' | ';
            $restore .= escapeshellcmd($binary).' '.$this->mysqlArguments($connection, $database);
            $this->shell($restore, $this->mysqlEnvironment($connection));

            config(['database.connections.restore_verify' => [...$connection, 'database' => $database]]);
            DB::purge('restore_verify');
            $tables = collect(DB::connection('restore_verify')->select('SHOW TABLES'))
                ->map(fn (object $row): string => (string) array_values((array) $row)[0])
                ->sort()->values();
            if ($tables->isEmpty()) throw new RuntimeException('Restore completed without any tables.');
            $migrationCount = DB::connection('restore_verify')->table('migrations')->count();
            if ($migrationCount < 1) throw new RuntimeException('Restore has no migration history.');
            $schemaFingerprint = hash('sha256', $tables->implode("\n"));
            $payload = [
                'verifiedAtUtc' => gmdate(DATE_ATOM), 'database' => $database,
                'dumpSha256' => hash_file('sha256', $dump), 'dumpBytes' => filesize($dump),
                'tableCount' => $tables->count(), 'migrationCount' => $migrationCount,
                'schemaFingerprint' => $schemaFingerprint, 'dataWasNotExported' => true,
            ];
            $directory = dirname($evidencePath);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create the evidence directory.');
            }
            file_put_contents($evidencePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
            $this->info('Restore drill verified. Evidence: '.$evidencePath);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->failVerification('Restore drill failed: '.$exception->getMessage());
        } finally {
            if (!(bool) $this->option('keep')) {
                try { $this->mysql($binary, $connection, ['-e', 'DROP DATABASE IF EXISTS `'.$database.'`']); } catch (Throwable) { $this->warn('Could not remove disposable restore database; remove it manually.'); }
            }
        }
    }

    private function mysql(string $binary, array $connection, array $arguments): void
    {
        $this->shell(escapeshellcmd($binary).' '.$this->mysqlArguments($connection).' '.implode(' ', array_map('escapeshellarg', $arguments)), $this->mysqlEnvironment($connection));
    }

    private function mysqlArguments(array $connection, ?string $database = null): string
    {
        return '--protocol=TCP --host='.escapeshellarg((string) $connection['host']).' --port='.escapeshellarg((string) $connection['port']).' --user='.escapeshellarg((string) $connection['username']).($database ? ' '.escapeshellarg($database) : '');
    }

    private function mysqlEnvironment(array $connection): array { return ['MYSQL_PWD' => (string) ($connection['password'] ?? '')]; }

    private function shell(string $command, array $environment): void
    {
        $process = proc_open(['sh', '-lc', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment + $_ENV);
        if (!is_resource($process)) throw new RuntimeException('Could not execute MySQL client.');
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException(trim($stderr ?: $stdout ?: 'MySQL client failed.'));
    }

    private function failVerification(string $message): int { $this->error($message); return self::FAILURE; }
}
