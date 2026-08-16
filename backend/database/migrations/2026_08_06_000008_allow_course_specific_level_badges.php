<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'user_level';
    private const LEGACY_INDEX = 'user_level_user_id_level_id_unique';
    private const COURSE_INDEX = 'user_level_user_level_course_unique';

    public function up(): void
    {
        // Add the stronger replacement before removing the legacy guard. If a
        // deployment is interrupted, the table remains protected by at least
        // one unique index at every point.
        if (!$this->indexExists(self::COURSE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'level_id', 'course_id'],
                    self::COURSE_INDEX
                );
            });
        }

        if ($this->indexExists(self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_INDEX);
            });
        }
    }

    public function down(): void
    {
        // Rolling back after learners have earned the same level in several
        // courses would make rows collide. Refuse that destructive rollback
        // and leave the valid production data/schema untouched.
        $hasCollisions = DB::table(self::TABLE)
            ->select(['user_id', 'level_id'])
            ->groupBy(['user_id', 'level_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollisions) {
            throw new \RuntimeException(
                'Cannot restore the legacy user_level unique index: course-specific badge awards exist.'
            );
        }

        if (!$this->indexExists(self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(['user_id', 'level_id'], self::LEGACY_INDEX);
            });
        }

        if ($this->indexExists(self::COURSE_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::COURSE_INDEX);
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . self::TABLE;

        $builder = $connection->getSchemaBuilder();
        if (method_exists($builder, 'getIndexes')) {
            foreach ($builder->getIndexes(self::TABLE) as $index) {
                if (strcasecmp((string) ($index['name'] ?? ''), $indexName) === 0) {
                    return true;
                }
            }

            return false;
        }

        return match ($connection->getDriverName()) {
            'sqlite' => collect($connection->select(
                "PRAGMA index_list('" . str_replace("'", "''", $table) . "')"
            ))->contains(fn (object $index): bool => strcasecmp((string) ($index->name ?? ''), $indexName) === 0),
            'mysql', 'mariadb' => $connection->selectOne(
                'SELECT 1 FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$table, $indexName]
            ) !== null,
            'pgsql' => $connection->selectOne(
                'SELECT 1 FROM pg_indexes '
                . 'WHERE schemaname = current_schema() AND tablename = ? AND indexname = ? LIMIT 1',
                [$table, $indexName]
            ) !== null,
            'sqlsrv' => $connection->selectOne(
                'SELECT TOP 1 1 AS present FROM sys.indexes i '
                . 'INNER JOIN sys.objects o ON o.object_id = i.object_id '
                . 'WHERE o.name = ? AND i.name = ?',
                [$table, $indexName]
            ) !== null,
            default => throw new \RuntimeException(
                'Cannot inspect indexes for unsupported database driver: ' . $connection->getDriverName()
            ),
        };
    }
};
