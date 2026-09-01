<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('courses', 'authoring_version')) {
            Schema::table('courses', fn (Blueprint $table) =>
                $table->unsignedBigInteger('authoring_version')->default(1)->after('is_catalog_visible')
            );
        }
        $this->addRequestColumn('courses', 'authoring_version');
        $this->addRequestColumn('lists', 'type');
        $this->addRequestColumn('questions', 'list_id');
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->dropUnique(['authoring_request_id']);
            $table->dropColumn('authoring_request_id');
        });
        Schema::table('lists', function (Blueprint $table): void {
            $table->dropUnique(['authoring_request_id']);
            $table->dropColumn('authoring_request_id');
        });
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropUnique(['authoring_request_id']);
            $table->dropColumn('authoring_request_id');
            $table->dropColumn('authoring_version');
        });
    }

    private function addRequestColumn(string $tableName, string $after): void
    {
        if (!Schema::hasColumn($tableName, 'authoring_request_id')) {
            Schema::table($tableName, fn (Blueprint $table) =>
                $table->uuid('authoring_request_id')->nullable()->after($after)
            );
        }
        if (!Schema::hasIndex($tableName, ['authoring_request_id'], 'unique')) {
            Schema::table($tableName, fn (Blueprint $table) =>
                $table->unique('authoring_request_id')
            );
        }
    }
};
