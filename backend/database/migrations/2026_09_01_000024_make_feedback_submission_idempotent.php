<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('feedback_reports', 'client_request_id')) {
            Schema::table('feedback_reports', fn (Blueprint $table) =>
                $table->uuid('client_request_id')->nullable()->after('public_id')
            );
        }
        if (!Schema::hasColumn('feedback_reports', 'request_fingerprint')) {
            Schema::table('feedback_reports', fn (Blueprint $table) =>
                $table->char('request_fingerprint', 64)->nullable()->after('client_request_id')
            );
        }
        if (!Schema::hasIndex('feedback_reports', ['client_request_id'], 'unique')) {
            Schema::table('feedback_reports', fn (Blueprint $table) =>
                $table->unique('client_request_id')
            );
        }
    }

    public function down(): void
    {
        Schema::table('feedback_reports', function (Blueprint $table): void {
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['client_request_id', 'request_fingerprint']);
        });
    }
};
