<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('certificates', 'generation_lease_id')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->uuid('generation_lease_id')->nullable()->after('image_path')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('certificates', 'generation_lease_id')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->dropIndex(['generation_lease_id']);
                $table->dropColumn('generation_lease_id');
            });
        }
    }
};
