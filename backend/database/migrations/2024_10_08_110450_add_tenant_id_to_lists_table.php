<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        if (! Schema::hasTable('lists') || Schema::hasColumn('lists', 'tenant_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('lists') || ! Schema::hasColumn('lists', 'tenant_id')) {
            return;
        }

        Schema::table('lists', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
    }
};
