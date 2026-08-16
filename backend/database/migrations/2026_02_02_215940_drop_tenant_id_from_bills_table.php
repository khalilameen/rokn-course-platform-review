<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // SQLite rebuilds the whole table for dropColumn and can break inbound
        // foreign keys. The column is harmless in disposable SQLite databases;
        // production MySQL still removes it normally.
        if (DB::connection()->getDriverName() === 'sqlite' || ! Schema::hasColumn('bills', 'tenant_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (DB::connection()->getDriverName() === 'sqlite' || Schema::hasColumn('bills', 'tenant_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
    }
};
