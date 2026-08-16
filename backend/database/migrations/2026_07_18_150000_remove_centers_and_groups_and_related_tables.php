<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Obsolete compatibility columns/tables may remain in disposable
        // SQLite test databases. Avoid table rebuilds that break inbound FKs;
        // production MySQL still performs the full cleanup below.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Convert any courses with course_type 'center' or 'both' to 'online'
        if (Schema::hasTable('courses') && Schema::hasColumn('courses', 'course_type')) {
            DB::table('courses')
                ->whereIn('course_type', ['center', 'both'])
                ->update(['course_type' => 'online']);
        }

        Schema::disableForeignKeyConstraints();

        // Drop group_id column and foreign key from users table
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'group_id')) {
            Schema::table('users', function (Blueprint $table) {
                try {
                    $table->dropForeign(['group_id']);
                } catch (\Exception $e) {
                    // Foreign key might not exist or might have a different name
                }
                $table->dropColumn('group_id');
            });
        }

        // Drop center_contacts column from design_settings table
        if (Schema::hasTable('design_settings') && Schema::hasColumn('design_settings', 'center_contacts')) {
            Schema::table('design_settings', function (Blueprint $table) {
                $table->dropColumn('center_contacts');
            });
        }

        // Drop obsolete tables
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('group_subscriptions');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('course_group');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('centers');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Since centers and groups removal is permanent, reverse is handled via initial migrations if ever needed.
    }
};
