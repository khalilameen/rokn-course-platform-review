<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_video_uploads')) {
            return;
        }
        Schema::create('portfolio_video_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('portfolio_item_id')->constrained('portfolio_items')->cascadeOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 100);
            $table->string('original_name', 255);
            $table->uuid('video_guid')->nullable()->unique();
            $table->uuid('allocation_token')->nullable()->index();
            $table->string('status', 24)->default('allocating');
            $table->timestamp('expires_at');
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'portfolio_item_id', 'idempotency_key'], 'portfolio_video_upload_owner_request_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_video_uploads');
    }
};
