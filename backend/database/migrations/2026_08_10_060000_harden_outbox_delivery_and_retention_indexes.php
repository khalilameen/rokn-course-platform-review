<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('outbox_events')) {
            Schema::table('outbox_events', function (Blueprint $table): void {
                if (!Schema::hasColumn('outbox_events', 'dispatched_at')) {
                    $table->timestamp('dispatched_at')->nullable()->after('available_at');
                }
                if (!Schema::hasColumn('outbox_events', 'locked_at')) {
                    $table->timestamp('locked_at')->nullable()->after('dispatched_at');
                }
            });
            Schema::table('outbox_events', function (Blueprint $table): void {
                $table->index(['status', 'dispatched_at', 'id'], 'outbox_dispatch_recovery');
                $table->index(['status', 'delivered_at', 'id'], 'outbox_delivered_retention');
                $table->index(['status', 'locked_at', 'id'], 'outbox_stale_claims');
            });
        }

        if (Schema::hasTable('webhook_deliveries')) {
            Schema::table('webhook_deliveries', function (Blueprint $table): void {
                $table->index(['status', 'delivered_at', 'id'], 'webhook_delivery_retention');
            });
        }

        if (Schema::hasTable('product_events')) {
            Schema::table('product_events', function (Blueprint $table): void {
                $table->index(['received_at', 'id'], 'product_events_retention');
            });
        }

        if (Schema::hasTable('playback_sessions')) {
            Schema::table('playback_sessions', function (Blueprint $table): void {
                $table->index(['ended_at', 'id'], 'playback_sessions_completed_retention');
                $table->index(['started_at', 'ended_at', 'id'], 'playback_sessions_abandoned_retention');
            });
        }

        $driver = DB::connection()->getDriverName();
        if (
            in_array($driver, ['mysql', 'pgsql'], true)
            && Schema::hasTable('webhook_deliveries')
            && Schema::hasTable('webhook_endpoints')
            && Schema::hasTable('outbox_events')
        ) {
            $orphanEndpoints = DB::table('webhook_deliveries as deliveries')
                ->leftJoin('webhook_endpoints as endpoints', 'endpoints.id', '=', 'deliveries.webhook_endpoint_id')
                ->whereNull('endpoints.id')
                ->exists();
            $orphanEvents = DB::table('webhook_deliveries as deliveries')
                ->leftJoin('outbox_events as events', 'events.id', '=', 'deliveries.outbox_event_id')
                ->whereNull('events.id')
                ->exists();
            if ($orphanEndpoints || $orphanEvents) {
                throw new RuntimeException('Webhook deliveries contain orphan rows; repair them before migration.');
            }

            Schema::table('webhook_deliveries', function (Blueprint $table): void {
                $table->foreign('webhook_endpoint_id', 'webhook_delivery_endpoint_foreign')
                    ->references('id')->on('webhook_endpoints')->cascadeOnDelete();
                $table->foreign('outbox_event_id', 'webhook_delivery_outbox_foreign')
                    ->references('id')->on('outbox_events')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'pgsql'], true) && Schema::hasTable('webhook_deliveries')) {
            Schema::table('webhook_deliveries', function (Blueprint $table): void {
                $table->dropForeign('webhook_delivery_endpoint_foreign');
                $table->dropForeign('webhook_delivery_outbox_foreign');
            });
        }

        if (Schema::hasTable('playback_sessions')) {
            Schema::table('playback_sessions', function (Blueprint $table): void {
                $table->dropIndex('playback_sessions_completed_retention');
                $table->dropIndex('playback_sessions_abandoned_retention');
            });
        }
        if (Schema::hasTable('product_events')) {
            Schema::table('product_events', fn (Blueprint $table) => $table->dropIndex('product_events_retention'));
        }
        if (Schema::hasTable('webhook_deliveries')) {
            Schema::table('webhook_deliveries', fn (Blueprint $table) => $table->dropIndex('webhook_delivery_retention'));
        }
        if (Schema::hasTable('outbox_events')) {
            Schema::table('outbox_events', function (Blueprint $table): void {
                $table->dropIndex('outbox_dispatch_recovery');
                $table->dropIndex('outbox_delivered_retention');
                $table->dropIndex('outbox_stale_claims');
                $table->dropColumn(['dispatched_at', 'locked_at']);
            });
        }
    }
};
