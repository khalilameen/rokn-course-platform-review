<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(100);
            $table->string('owner', 120);
            $table->string('reason', 255);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feature_flags');
    }
};
