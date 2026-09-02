<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('social_identity_guards')) {
            return;
        }

        Schema::create('social_identity_guards', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_hash', 64)->unique();
            $table->timestamp('deletion_started_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identity_guards');
    }
};
