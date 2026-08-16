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
        // See the matching tenant cleanup migrations: SQLite's table rebuild
        // can invalidate inbound foreign keys, while MySQL drops the column.
        if (DB::connection()->getDriverName() === 'sqlite' || ! Schema::hasColumn('course_enrollments', 'tenant_id')) {
            return;
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
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
        if (DB::connection()->getDriverName() === 'sqlite' || Schema::hasColumn('course_enrollments', 'tenant_id')) {
            return;
        }

        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });
    }
};
