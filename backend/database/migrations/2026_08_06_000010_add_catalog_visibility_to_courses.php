<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->boolean('is_catalog_visible')->default(false)->after('is_coming_soon');
            $table->index(
                ['is_catalog_visible', 'is_coming_soon', 'created_at'],
                'courses_catalog_visibility'
            );
        });

        // Already-published courses remain visible. Existing drafts stay
        // hidden until an administrator deliberately announces them.
        DB::table('courses')->where('is_coming_soon', false)->update([
            'is_catalog_visible' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex('courses_catalog_visibility');
            $table->dropColumn('is_catalog_visible');
        });
    }
};
