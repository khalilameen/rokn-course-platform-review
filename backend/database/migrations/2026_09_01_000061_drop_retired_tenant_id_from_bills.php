<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        if (Schema::hasColumn('bills', 'tenant_id')) {
            $hasTenantIndex = collect(Schema::getIndexes('bills'))->contains(
                fn (array $index): bool => ($index['name'] ?? null) === 'bills_tenant_id_index'
            );
            if ($hasTenantIndex) {
                Schema::table('bills', function (Blueprint $table): void {
                    $table->dropIndex('bills_tenant_id_index');
                });
            }

            Schema::table('bills', function (Blueprint $table): void {
                $table->dropColumn('tenant_id');
            });
        }

        // Package top-ups have no course. Their invoices and later reversals
        // must remain writable without inventing a course relationship.
        if (Schema::hasColumn('bills', 'course_id')) {
            Schema::table('bills', function (Blueprint $table): void {
                $table->unsignedBigInteger('course_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Multitenancy was permanently retired. Reintroducing a required
        // tenant identifier would make restored financial rows unwritable.
    }
};
