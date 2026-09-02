<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** @var list<string> */
    private array $tables = ['admin_notifications', 'coupons', 'users', 'categories', 'levels'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'authoring_request_id')) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->uuid('authoring_request_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'authoring_request_id')) {
                continue;
            }
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['authoring_request_id']);
                $blueprint->dropColumn('authoring_request_id');
            });
        }
    }
};
