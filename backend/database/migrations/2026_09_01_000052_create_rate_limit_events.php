<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rate_limit_events')) {
            return;
        }

        Schema::create('rate_limit_events', function (Blueprint $table): void {
            $table->id();
            $table->char('bucket_key_hash', 64);
            $table->string('route_name', 190);
            $table->string('method', 10);
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('window_started_at');
            $table->unsignedInteger('hit_count')->default(1);
            $table->unsignedInteger('retry_after_seconds')->default(0);
            $table->timestamps();
            $table->unique(
                ['bucket_key_hash', 'route_name', 'window_started_at'],
                'rate_limit_events_bucket_route_window'
            );
            $table->index(['window_started_at', 'route_name'], 'rate_limit_events_window_route');
            $table->index(['user_id', 'window_started_at'], 'rate_limit_events_user_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_events');
    }
};
