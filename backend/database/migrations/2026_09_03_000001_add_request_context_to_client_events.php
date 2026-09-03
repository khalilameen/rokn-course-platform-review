<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->after('client_event_id')->index();
            $table->string('endpoint', 160)->nullable()->after('error_fingerprint')->index();
            $table->string('request_id', 128)->nullable()->after('endpoint')->index();
        });
    }

    public function down(): void
    {
        Schema::table('client_events', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['endpoint']);
            $table->dropIndex(['request_id']);
            $table->dropColumn(['user_id', 'endpoint', 'request_id']);
        });
    }
};
