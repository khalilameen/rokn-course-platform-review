<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_event_id')->unique();
            $table->string('event_name', 48);
            $table->string('severity', 12)->default('info');
            $table->string('app_version', 32)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('platform', 12);
            $table->unsignedTinyInteger('os_major')->nullable();
            $table->string('device_tier', 12)->default('unknown');
            $table->string('network_type', 12)->nullable();
            $table->string('screen_key', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('error_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->useCurrent();

            $table->index(['event_name', 'occurred_at'], 'client_events_name_timeline');
            $table->index(['severity', 'occurred_at'], 'client_events_severity_timeline');
            $table->index('received_at', 'client_events_retention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_events');
    }
};
