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
        if (DB::connection()->getDriverName() === 'sqlite') {
            // These retired nullable columns are harmless in disposable test
            // databases. SQLite would rebuild several referenced tables and
            // corrupt their legacy FK graph; MySQL production still performs
            // the actual cleanup below.
            return;
        }

        $tablesWithTenantId = [
            'course_codes',
            'course_code_usages',
            'exam_attempts',
            'exam_answers',
            'exam_security_logs',
            'payment_methods',
            'lists',
            'visitors',
            'design_settings',
            'centers',
            'groups',
            'appointments',
            'attendances',
            'settings',
            'course_pdfs',
            'paths',
            'pages'
        ];

        foreach ($tablesWithTenantId as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                // Safely drop foreign key if it exists
                try {
                    DB::statement("ALTER TABLE `{$tableName}` DROP FOREIGN KEY `{$tableName}_tenant_id_foreign`");
                } catch (\Exception $e) {
                    // Foreign key does not exist on this table
                }

                // Safely drop the column
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }

        // Now drop the multitenancy tables
        Schema::dropIfExists('tenant_themes');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('themes');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No down migration required as multitenancy is permanently removed
    }
};
