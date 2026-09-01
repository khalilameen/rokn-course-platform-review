<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('portfolio_items', 'client_request_id')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->uuid('client_request_id')->nullable()->after('user_id')
            );
        }
        if (!Schema::hasColumn('portfolio_items', 'request_fingerprint')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->char('request_fingerprint', 64)->nullable()->after('client_request_id')
            );
        }
        if (!Schema::hasIndex('portfolio_items', ['user_id', 'client_request_id'], 'unique')) {
            Schema::table('portfolio_items', fn (Blueprint $table) =>
                $table->unique(['user_id', 'client_request_id'])
            );
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'client_request_id']);
            $table->dropColumn(['client_request_id', 'request_fingerprint']);
        });
    }
};
