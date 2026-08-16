<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('watch_history_enabled')->default(true)->after('notifications_status');
            $table->boolean('marketing_notifications_enabled')->default(false)->after('watch_history_enabled');
            $table->timestamp('terms_accepted_at')->nullable()->after('marketing_notifications_enabled');
            $table->timestamp('privacy_notice_acknowledged_at')->nullable()->after('terms_accepted_at');
            $table->string('legal_notice_version', 32)->nullable()->after('privacy_notice_acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'watch_history_enabled',
                'marketing_notifications_enabled',
                'terms_accepted_at',
                'privacy_notice_acknowledged_at',
                'legal_notice_version',
            ]);
        });
    }
};
