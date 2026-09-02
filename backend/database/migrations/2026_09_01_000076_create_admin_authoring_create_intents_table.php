<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_authoring_create_intents')) return;
        Schema::create('admin_authoring_create_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('route_name', 190);
            $table->char('parent_scope', 64);
            $table->uuid('intent_id');
            $table->char('request_fingerprint', 64);
            $table->string('status', 20)->default('processing');
            $table->text('response_location')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'route_name', 'parent_scope', 'intent_id'], 'admin_authoring_create_intent_unique');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_authoring_create_intents');
    }
};
