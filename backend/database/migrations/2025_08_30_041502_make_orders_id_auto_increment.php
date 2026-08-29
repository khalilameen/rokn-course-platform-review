<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)
            || !Schema::hasColumn('orders', 'id')
            || $this->isAutoIncrementing()) {
            return;
        }

        // Preserve every order id and every inbound foreign key. Dropping and
        // recreating this column would either fail or silently sever history.
        DB::statement(sprintf(
            'ALTER TABLE %s MODIFY %s BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
            $this->wrappedOrdersTable(),
            DB::connection()->getSchemaGrammar()->wrap('id')
        ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)
            || !Schema::hasColumn('orders', 'id')
            || !$this->isAutoIncrementing()) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s MODIFY %s BIGINT UNSIGNED NOT NULL',
            $this->wrappedOrdersTable(),
            DB::connection()->getSchemaGrammar()->wrap('id')
        ));
    }

    private function isAutoIncrementing(): bool
    {
        $column = DB::selectOne(
            'SELECT extra FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            [DB::connection()->getTablePrefix() . 'orders', 'id']
        );

        return str_contains(strtolower((string) ($column->extra ?? '')), 'auto_increment');
    }

    private function wrappedOrdersTable(): string
    {
        return DB::connection()->getSchemaGrammar()->wrapTable('orders');
    }
};
