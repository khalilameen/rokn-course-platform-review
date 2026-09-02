<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_media_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('probe_generation')->default(0)->after('last_probe_at');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_media_states', function (Blueprint $table): void {
            $table->dropColumn('probe_generation');
        });
    }
};
