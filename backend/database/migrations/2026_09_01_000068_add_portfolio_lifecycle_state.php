<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('portfolio_items', 'expected_media_count')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->unsignedTinyInteger('expected_media_count')->default(0)->after('sort_order');
            });
        }
        if (!Schema::hasColumn('portfolio_items', 'deletion_started_at')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->timestamp('deletion_started_at')->nullable()->after('expected_media_count')->index();
            });
        }
        if (!Schema::hasColumn('portfolio_media', 'deletion_lease_id')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->uuid('deletion_lease_id')->nullable()->after('sort_order')->index();
            });
        }
        if (!Schema::hasColumn('portfolio_media', 'deletion_started_at')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->timestamp('deletion_started_at')->nullable()->after('deletion_lease_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_media', 'deletion_started_at')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->dropColumn('deletion_started_at');
            });
        }
        if (Schema::hasColumn('portfolio_media', 'deletion_lease_id')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->dropIndex(['deletion_lease_id']);
                $table->dropColumn('deletion_lease_id');
            });
        }
        if (Schema::hasColumn('portfolio_items', 'deletion_started_at')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->dropIndex(['deletion_started_at']);
                $table->dropColumn('deletion_started_at');
            });
        }
        if (Schema::hasColumn('portfolio_items', 'expected_media_count')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->dropColumn('expected_media_count');
            });
        }
    }
};
