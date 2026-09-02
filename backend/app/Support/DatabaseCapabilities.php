<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Process-local schema capabilities for rolling-deploy compatibility.
 *
 * Schema inspection queries information_schema and must not run on learner
 * hot paths. A deployment restarts PHP/queue workers after migrations, which
 * is the invalidation boundary. Tests or an in-process migration may call
 * flush() explicitly.
 */
final class DatabaseCapabilities
{
    /** @var array<string, bool> */
    private static array $tables = [];

    /** @var array<string, bool> */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        $key = self::connectionKey() . ':table:' . $table;

        return self::$tables[$key] ??= Schema::hasTable($table);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = self::connectionKey() . ':column:' . $table . ':' . $column;

        return self::$columns[$key] ??= Schema::hasColumn($table, $column);
    }

    /** @param list<string> $columns */
    public static function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!self::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
    }

    private static function connectionKey(): string
    {
        $connection = DB::connection();

        return $connection->getName() . ':' . (string) $connection->getDatabaseName();
    }
}
