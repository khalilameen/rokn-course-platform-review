<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bunny_direct_uploads')
            || Schema::hasColumn('bunny_direct_uploads', 'allocation_token')) {
            return;
        }

        Schema::table('bunny_direct_uploads', function (Blueprint $table): void {
            $table->uuid('allocation_token')->nullable()->index()->after('video_guid');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bunny_direct_uploads')
            || !Schema::hasColumn('bunny_direct_uploads', 'allocation_token')) {
            return;
        }

        Schema::table('bunny_direct_uploads', function (Blueprint $table): void {
            $table->dropColumn('allocation_token');
        });
    }
};
