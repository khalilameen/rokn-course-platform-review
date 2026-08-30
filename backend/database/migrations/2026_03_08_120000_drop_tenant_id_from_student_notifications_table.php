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
        if (DB::connection()->getDriverName() === 'sqlite' || ! Schema::hasColumn('student_notifications', 'tenant_id')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
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
        if (DB::connection()->getDriverName() === 'sqlite' || Schema::hasColumn('student_notifications', 'tenant_id')) {
            return;
        }

        Schema::table('student_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('read_at');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
        });
    }
};
