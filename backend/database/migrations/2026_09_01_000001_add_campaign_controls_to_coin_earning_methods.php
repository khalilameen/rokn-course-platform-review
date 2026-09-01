<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** MySQL commits DDL implicitly; every step below is safe to resume. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasIndex('coin_earning_methods', 'coin_earning_methods_action_key_unique')) {
            Schema::table('coin_earning_methods', fn (Blueprint $table) =>
                $table->dropUnique('coin_earning_methods_action_key_unique')
            );
        }
        if (!Schema::hasIndex('coin_earning_methods', 'coin_earning_methods_action_key_index')) {
            Schema::table('coin_earning_methods', fn (Blueprint $table) =>
                $table->index('action_key', 'coin_earning_methods_action_key_index')
            );
        }

        $columns = [
            'campaign_key' => fn (Blueprint $table) => $table->string('campaign_key', 80)->nullable()->after('action_key'),
            'starts_at' => fn (Blueprint $table) => $table->timestamp('starts_at')->nullable()->after('verification_delay_seconds'),
            'ends_at' => fn (Blueprint $table) => $table->timestamp('ends_at')->nullable()->after('starts_at'),
            'total_claim_limit' => fn (Blueprint $table) => $table->unsignedInteger('total_claim_limit')->nullable()->after('ends_at'),
            'deleted_at' => fn (Blueprint $table) => $table->softDeletes(),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('coin_earning_methods', $column)) {
                Schema::table('coin_earning_methods', $definition);
            }
        }

        if (!Schema::hasIndex('coin_earning_methods', 'coin_campaign_availability')) {
            Schema::table('coin_earning_methods', fn (Blueprint $table) =>
                $table->index(['is_active', 'starts_at', 'ends_at'], 'coin_campaign_availability')
            );
        }
        if (!Schema::hasIndex('coin_earning_methods', 'coin_campaign_key_unique')) {
            // The campaign key is the immutable ledger identity. Application
            // validation alone cannot protect two concurrent dashboard saves.
            Schema::table('coin_earning_methods', fn (Blueprint $table) =>
                $table->unique('campaign_key', 'coin_campaign_key_unique')
            );
        }
    }

    public function down(): void
    {
        // Additive production migration. Completed rewards may reference a
        // campaign key, and dropping deleted_at would resurrect retired tasks.
        // Older releases safely ignore the expanded columns and indexes.
    }
};
