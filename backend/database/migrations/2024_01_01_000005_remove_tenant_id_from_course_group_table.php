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
        if (! Schema::hasTable('course_group') || ! Schema::hasColumn('course_group', 'tenant_id')) {
            return;
        }

        // SQLite cannot rebuild a table while an index still references the
        // column being removed. MySQL also benefits from making the dependency
        // explicit instead of relying on engine-specific implicit cleanup.
        if (Schema::hasIndex('course_group', 'course_group_tenant_id_index')) {
            Schema::table('course_group', function (Blueprint $table) {
                $table->dropIndex('course_group_tenant_id_index');
            });
        }

        Schema::table('course_group', function (Blueprint $table) {
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
        if (! Schema::hasTable('course_group') || Schema::hasColumn('course_group', 'tenant_id')) {
            return;
        }

        Schema::table('course_group', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->index()->after('id');
        });
    }
};
