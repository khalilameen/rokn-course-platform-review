<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_authoring_draft_receipts')) {
            return;
        }
        Schema::create('admin_authoring_draft_receipts', function (Blueprint $table): void {
            $table->uuid('receipt')->primary();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('consumed_at')->index();
            $table->index(['actor_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_authoring_draft_receipts');
    }
};
