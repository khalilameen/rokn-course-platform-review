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
        if (Schema::hasTable('social_oauth_attempts')) {
            return;
        }

        Schema::create('social_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->char('completion_hash', 64)->nullable()->unique();
            $table->string('provider', 24);
            $table->string('return_to', 255);
            $table->string('code_challenge', 128)->nullable();
            $table->text('encrypted_token')->nullable();
            $table->timestamp('state_expires_at');
            $table->timestamp('state_consumed_at')->nullable();
            $table->timestamp('completion_expires_at')->nullable();
            $table->timestamp('completion_consumed_at')->nullable();
            $table->timestamps();

            $table->index(['state_expires_at', 'state_consumed_at'], 'social_oauth_state_expiry_idx');
            $table->index(['completion_expires_at', 'completion_consumed_at'], 'social_oauth_completion_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_oauth_attempts');
    }
};
