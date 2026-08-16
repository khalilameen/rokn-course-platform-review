<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SQLite must toggle foreign-key enforcement outside a transaction. */
    public $withinTransaction = false;

    /**
     * Legacy migrations created several content tables before `courses` and
     * `course_sections`. SQLite permits those forward references in CREATE
     * TABLE statements; MySQL requires the referenced table to exist first.
     *
     * @var list<array{table:string,column:string,target:string,target_column:string,on_delete:string,name:string}>
     */
    private const FOREIGN_KEYS = [
        [
            'table' => 'course_codes',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'course_codes_course_id_foreign',
        ],
        [
            'table' => 'orders',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'orders_course_id_foreign',
        ],
        [
            'table' => 'bills',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'bills_course_id_foreign',
        ],
        [
            'table' => 'course_enrollments',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'course_enrollments_course_id_foreign',
        ],
        [
            'table' => 'exam_attempts',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'exam_attempts_course_id_foreign',
        ],
        [
            'table' => 'exam_attempts',
            'column' => 'section_id',
            'target' => 'course_sections',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'exam_attempts_section_id_foreign',
        ],
        [
            'table' => 'course_modules',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'course_modules_course_id_foreign',
        ],
        [
            'table' => 'course_sections',
            'column' => 'course_id',
            'target' => 'courses',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'course_sections_course_id_foreign',
        ],
        [
            'table' => 'questions',
            'column' => 'list_id',
            'target' => 'lists',
            'target_column' => 'id',
            'on_delete' => 'cascade',
            'name' => 'questions_list_id_foreign',
        ],
    ];

    public function up(): void
    {
        // Most historical CREATE TABLE statements retain forward constraints
        // on SQLite. `bills` is the exception: a later enum-to-string migration
        // rebuilds that table and SQLite loses the forward course constraint.
        // Bills has no inbound foreign keys, so repairing only that table is
        // safe; rebuilding the other legacy tables here would not be.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->installOnSqliteIfSafe(self::FOREIGN_KEYS[2]);

            return;
        }

        foreach (self::FOREIGN_KEYS as $definition) {
            $this->installIfSafe($definition);
        }
    }

    public function down(): void
    {
        // Forward-only repair. Some constraints predate this migration on
        // existing databases, so a rollback must not remove their integrity.
    }

    /**
     * @param array{table:string,column:string,target:string,target_column:string,on_delete:string,name:string} $definition
     */
    private function installOnSqliteIfSafe(array $definition): void
    {
        if (
            !Schema::hasTable($definition['table'])
            || !Schema::hasTable($definition['target'])
            || !Schema::hasColumn($definition['table'], $definition['column'])
            || !Schema::hasColumn($definition['target'], $definition['target_column'])
        ) {
            Log::warning('Deferred SQLite foreign key was not installed because its schema is incomplete.', $definition);

            return;
        }

        $existing = $this->foreignKeyForColumn($definition['table'], $definition['column']);
        if ($existing !== null && $existing['target'] === $definition['target']) {
            return;
        }

        if ($this->hasOrphans($definition)) {
            Log::warning('Deferred SQLite foreign key was not installed because legacy orphan rows require reconciliation.', $definition);

            return;
        }

        // An unexpected constraint is production data that requires a human
        // decision. Do not silently rewrite it in a release migration.
        if ($existing !== null) {
            Log::warning('Deferred SQLite foreign key was not installed because a different constraint already exists.', [
                ...$definition,
                'existing' => $existing,
            ]);

            return;
        }

        $this->rebuildSqliteTableWithForeignKey($definition);
    }

    /**
     * @param array{table:string,column:string,target:string,target_column:string,on_delete:string,name:string} $definition
     */
    private function installIfSafe(array $definition): void
    {
        if (
            !Schema::hasTable($definition['table'])
            || !Schema::hasTable($definition['target'])
            || !Schema::hasColumn($definition['table'], $definition['column'])
            || !Schema::hasColumn($definition['target'], $definition['target_column'])
        ) {
            Log::warning('Deferred foreign key was not installed because its schema is incomplete.', $definition);

            return;
        }

        $existing = $this->foreignKeyForColumn($definition['table'], $definition['column']);
        if ($existing !== null && $existing['target'] === $definition['target']) {
            return;
        }

        // Never delete or rewrite production records from a schema migration.
        // A legacy orphan remains usable for manual reconciliation, while clean
        // databases receive the invariant immediately.
        if ($this->hasOrphans($definition)) {
            Log::warning('Deferred foreign key was not installed because legacy orphan rows require reconciliation.', $definition);

            return;
        }

        if ($existing !== null) {
            Schema::table($definition['table'], function (Blueprint $table) use ($existing): void {
                $table->dropForeign($existing['name']);
            });
        }

        Schema::table($definition['table'], function (Blueprint $table) use ($definition): void {
            $table->foreign($definition['column'], $definition['name'])
                ->references($definition['target_column'])
                ->on($definition['target'])
                ->onDelete($definition['on_delete']);
        });
    }

    /**
     * @return array{name:string,target:string}|null
     */
    private function foreignKeyForColumn(string $tableName, string $column): ?array
    {
        $connection = Schema::getConnection();
        $prefix = $connection->getTablePrefix();
        $table = $prefix . $tableName;

        $builder = $connection->getSchemaBuilder();
        if (method_exists($builder, 'getForeignKeys')) {
            foreach ($builder->getForeignKeys($tableName) as $foreignKey) {
                $columns = $foreignKey['columns'] ?? [];
                if (!in_array($column, $columns, true)) {
                    continue;
                }

                return [
                    'name' => (string) ($foreignKey['name'] ?? ''),
                    'target' => $this->withoutPrefix(
                        (string) ($foreignKey['foreign_table'] ?? ''),
                        $prefix
                    ),
                ];
            }

            return null;
        }

        $foreignKey = match ($connection->getDriverName()) {
            'sqlite' => collect($connection->select(
                'PRAGMA foreign_key_list(' . $this->quoteSqliteIdentifier($table) . ')'
            ))->first(fn (object $key): bool => (string) ($key->from ?? '') === $column),
            'mysql', 'mariadb' => $connection->selectOne(
                'SELECT constraint_name AS name, referenced_table_name AS target '
                . 'FROM information_schema.key_column_usage '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? '
                . 'AND referenced_table_name IS NOT NULL LIMIT 1',
                [$table, $column]
            ),
            'pgsql' => $connection->selectOne(
                'SELECT tc.constraint_name AS name, ccu.table_name AS target '
                . 'FROM information_schema.table_constraints tc '
                . 'INNER JOIN information_schema.key_column_usage kcu '
                . 'ON tc.constraint_name = kcu.constraint_name AND tc.constraint_schema = kcu.constraint_schema '
                . 'INNER JOIN information_schema.constraint_column_usage ccu '
                . 'ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema '
                . "WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema() "
                . 'AND tc.table_name = ? AND kcu.column_name = ? LIMIT 1',
                [$table, $column]
            ),
            'sqlsrv' => $connection->selectOne(
                'SELECT TOP 1 fk.name AS name, referenced_object.name AS target '
                . 'FROM sys.foreign_key_columns fkc '
                . 'INNER JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id '
                . 'INNER JOIN sys.objects parent_object ON parent_object.object_id = fkc.parent_object_id '
                . 'INNER JOIN sys.columns parent_column '
                . 'ON parent_column.object_id = fkc.parent_object_id AND parent_column.column_id = fkc.parent_column_id '
                . 'INNER JOIN sys.objects referenced_object ON referenced_object.object_id = fkc.referenced_object_id '
                . 'WHERE parent_object.name = ? AND parent_column.name = ?',
                [$table, $column]
            ),
            default => throw new \RuntimeException(
                'Cannot inspect foreign keys for unsupported database driver: ' . $connection->getDriverName()
            ),
        };

        if ($foreignKey === null) {
            return null;
        }

        return [
            // SQLite's PRAGMA omits declared constraint names. Laravel's
            // conventional name is sufficient for diagnostics; SQLite never
            // drops a discovered constraint through this path.
            'name' => (string) ($foreignKey->name ?? ($tableName . '_' . $column . '_foreign')),
            'target' => $this->withoutPrefix((string) ($foreignKey->target ?? $foreignKey->table ?? ''), $prefix),
        ];
    }

    /**
     * SQLite cannot add a foreign key with ALTER TABLE. Rebuild the one safe
     * legacy table while preserving its columns, data, explicit indexes, and
     * triggers. The caller has already proved there are no orphaned rows.
     *
     * @param array{table:string,column:string,target:string,target_column:string,on_delete:string,name:string} $definition
     */
    private function rebuildSqliteTableWithForeignKey(array $definition): void
    {
        $connection = Schema::getConnection();
        $prefix = $connection->getTablePrefix();
        $table = $prefix . $definition['table'];
        $target = $prefix . $definition['target'];
        $temporary = $table . '__rokn_fk_rebuild';

        $tableSql = $connection->selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        if ($tableSql === null || !is_string($tableSql->sql ?? null)) {
            throw new \RuntimeException("Cannot read the SQLite schema for {$table}.");
        }

        if ($connection->selectOne(
            "SELECT 1 AS present FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$temporary]
        ) !== null) {
            throw new \RuntimeException("Refusing to overwrite an existing SQLite recovery table: {$temporary}.");
        }

        $closingParenthesis = strrpos($tableSql->sql, ')');
        if ($closingParenthesis === false) {
            throw new \RuntimeException("Cannot parse the SQLite schema for {$table}.");
        }

        $constraint = sprintf(
            ', CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
            $this->quoteSqliteIdentifier($definition['name']),
            $this->quoteSqliteIdentifier($definition['column']),
            $this->quoteSqliteIdentifier($target),
            $this->quoteSqliteIdentifier($definition['target_column']),
            strtoupper($definition['on_delete'])
        );
        $createSql = substr($tableSql->sql, 0, $closingParenthesis)
            . $constraint
            . substr($tableSql->sql, $closingParenthesis);

        $columns = Schema::getColumnListing($definition['table']);
        if ($columns === []) {
            throw new \RuntimeException("Cannot rebuild the empty SQLite schema for {$table}.");
        }

        $columnList = implode(', ', array_map($this->quoteSqliteIdentifier(...), $columns));
        $secondaryObjects = $connection->select(
            "SELECT type, name, sql FROM sqlite_master "
            . "WHERE tbl_name = ? AND type IN ('index', 'trigger') AND sql IS NOT NULL",
            [$table]
        );

        $connection->statement('PRAGMA foreign_keys = OFF');

        try {
            $connection->beginTransaction();
            $connection->statement(
                'ALTER TABLE ' . $this->quoteSqliteIdentifier($table)
                . ' RENAME TO ' . $this->quoteSqliteIdentifier($temporary)
            );
            $connection->statement($createSql);
            $connection->statement(
                'INSERT INTO ' . $this->quoteSqliteIdentifier($table) . " ({$columnList}) "
                . 'SELECT ' . $columnList . ' FROM ' . $this->quoteSqliteIdentifier($temporary)
            );
            $connection->statement('DROP TABLE ' . $this->quoteSqliteIdentifier($temporary));

            foreach ($secondaryObjects as $object) {
                if (is_string($object->sql ?? null) && $object->sql !== '') {
                    $connection->statement($object->sql);
                }
            }

            $violations = $connection->select(
                'PRAGMA foreign_key_check(' . $this->quoteSqliteIdentifier($table) . ')'
            );
            if ($violations !== []) {
                throw new \RuntimeException("SQLite foreign-key verification failed for {$table}.");
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            $connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    private function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function withoutPrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }

    /**
     * @param array{table:string,column:string,target:string,target_column:string,on_delete:string,name:string} $definition
     */
    private function hasOrphans(array $definition): bool
    {
        return DB::table($definition['table'])
            ->leftJoin(
                $definition['target'],
                $definition['target'] . '.' . $definition['target_column'],
                '=',
                $definition['table'] . '.' . $definition['column']
            )
            ->whereNotNull($definition['table'] . '.' . $definition['column'])
            ->whereNull($definition['target'] . '.' . $definition['target_column'])
            ->exists();
    }
};
