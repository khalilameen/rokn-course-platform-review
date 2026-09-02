<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_entitlement_usages', function (Blueprint $table): void {
            $table->unsignedInteger('unanswered_provider_requests')
                ->default(0)
                ->after('reserved_requests');
            $table->timestamp('unanswered_provider_last_at')
                ->nullable()
                ->after('unanswered_provider_requests');
            $table->timestamp('provider_exposure_paused_until')
                ->nullable()
                ->after('unanswered_provider_last_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_entitlement_usages', function (Blueprint $table): void {
            $table->dropColumn([
                'unanswered_provider_requests',
                'unanswered_provider_last_at',
                'provider_exposure_paused_until',
            ]);
        });
    }
};
