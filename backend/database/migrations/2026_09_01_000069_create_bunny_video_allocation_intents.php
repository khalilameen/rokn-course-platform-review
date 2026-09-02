<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bunny_video_allocation_intents')) {
            Schema::create('bunny_video_allocation_intents', function (Blueprint $table): void {
                $table->id();
                $table->uuid('marker')->unique();
                $table->string('video_guid', 64)->nullable()->index();
                $table->string('status', 24)->default('allocating')->index();
                $table->string('context', 48);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bunny_video_allocation_intents');
    }
};
