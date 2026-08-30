<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->string('google_product_id')->nullable()->unique()->after('coins');
            $table->string('apple_product_id')->nullable()->unique()->after('google_product_id');
            $table->boolean('google_enabled')->default(false)->after('apple_product_id');
            $table->boolean('apple_enabled')->default(false)->after('google_enabled');
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->decimal('direct_checkout_discount_percent', 5, 2)
                ->default(10)
                ->after('currency_code');
        });

        Schema::create('store_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->string('provider', 16);
            $table->string('product_id');
            $table->string('external_transaction_id');
            $table->char('purchase_token_hash', 64);
            $table->text('purchase_token');
            $table->string('environment', 16)->nullable();
            $table->string('status', 24)->default('verified');
            $table->json('provider_payload')->nullable();
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->unique(
                ['provider', 'external_transaction_id'],
                'store_purchase_provider_transaction_unique'
            );
            $table->unique(
                ['provider', 'purchase_token_hash'],
                'store_purchase_provider_token_unique'
            );
            $table->index(
                ['user_id', 'status', 'verified_at'],
                'store_purchase_user_timeline'
            );
        });

        Schema::create('store_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            $table->string('event_id');
            $table->string('event_type', 64);
            $table->string('status', 24)->default('received');
            $table->char('payload_sha256', 64);
            $table->json('payload')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'event_id'],
                'store_notification_provider_event_unique'
            );
            $table->index(
                ['provider', 'status', 'received_at'],
                'store_notification_review_queue'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_notification_events');
        Schema::dropIfExists('store_purchases');

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('direct_checkout_discount_percent');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropUnique(['google_product_id']);
            $table->dropUnique(['apple_product_id']);
            $table->dropColumn([
                'google_product_id',
                'apple_product_id',
                'google_enabled',
                'apple_enabled',
            ]);
        });
    }
};
