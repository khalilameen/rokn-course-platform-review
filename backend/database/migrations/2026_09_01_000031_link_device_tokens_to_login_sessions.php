<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $apiTokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($apiTokenTable) && !Schema::hasColumn($apiTokenTable, 'device_id')) {
            Schema::table($apiTokenTable, function (Blueprint $table): void {
                $table->uuid('device_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('user_device_tokens') && !Schema::hasColumn('user_device_tokens', 'device_id')) {
            Schema::table('user_device_tokens', function (Blueprint $table): void {
                $table->uuid('device_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $apiTokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($apiTokenTable) && Schema::hasColumn($apiTokenTable, 'device_id')) {
            Schema::table($apiTokenTable, function (Blueprint $table): void {
                $table->dropColumn('device_id');
            });
        }

        if (Schema::hasTable('user_device_tokens') && Schema::hasColumn('user_device_tokens', 'device_id')) {
            Schema::table('user_device_tokens', function (Blueprint $table): void {
                $table->dropColumn('device_id');
            });
        }
    }
};
