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
        $columns = [
            'parent_id',
            'is_catalog_visible',
            'is_coming_soon',
            'is_main_course',
            'home_sort_order',
            'created_at',
            'id',
        ];
        if (
            !Schema::hasTable('courses')
            || collect($columns)->contains(fn (string $column): bool => !Schema::hasColumn('courses', $column))
            || Schema::hasIndex('courses', 'courses_catalogue_page_order_v3')
        ) {
            return;
        }

        Schema::table('courses', function (Blueprint $table) use ($columns): void {
            $table->index(
                $columns,
                'courses_catalogue_page_order_v3'
            );
        });
    }

    public function down(): void
    {
        if (
            !Schema::hasTable('courses')
            || !Schema::hasIndex('courses', 'courses_catalogue_page_order_v3')
        ) {
            return;
        }

        Schema::table('courses', fn (Blueprint $table) =>
            $table->dropIndex('courses_catalogue_page_order_v3')
        );
    }
};
