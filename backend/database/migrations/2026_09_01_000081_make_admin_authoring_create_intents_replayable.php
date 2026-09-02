<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_authoring_create_intents')) return;

        Schema::table('admin_authoring_create_intents', function (Blueprint $table): void {
            if (!Schema::hasColumn('admin_authoring_create_intents', 'response_kind')) {
                $table->string('response_kind', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'response_body')) {
                $table->longText('response_body')->nullable()->after('response_status');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'response_content_type')) {
                $table->string('response_content_type', 190)->nullable()->after('response_body');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'resource_type')) {
                $table->string('resource_type', 190)->nullable()->after('response_content_type');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'resource_id')) {
                $table->string('resource_id', 190)->nullable()->after('resource_type');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('resource_id');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('admin_authoring_create_intents', 'failure_code')) {
                $table->string('failure_code', 80)->nullable()->after('failed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_authoring_create_intents')) return;
        $columns = [
            'response_kind', 'response_body', 'response_content_type',
            'resource_type', 'resource_id', 'completed_at', 'failed_at', 'failure_code',
        ];
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('admin_authoring_create_intents', $column)
        ));
        if ($existing !== []) {
            Schema::table('admin_authoring_create_intents', fn (Blueprint $table) => $table->dropColumn($existing));
        }
    }
};
