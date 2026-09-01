<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recovery_markers')) return;

        Schema::create('recovery_markers', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32)->unique();
            $table->uuid('generation')->unique();
            $table->string('encryption_key_id', 100);
            $table->text('encrypted_probe');
            $table->char('probe_hash', 64);
            $table->timestamp('checkpoint_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_markers');
    }
};
