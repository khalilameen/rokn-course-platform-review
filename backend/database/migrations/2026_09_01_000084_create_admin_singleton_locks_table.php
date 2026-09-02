<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_singleton_locks')) {
            return;
        }
        Schema::create('admin_singleton_locks', function (Blueprint $table): void {
            $table->string('lock_key', 80)->primary();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_singleton_locks');
    }
};
