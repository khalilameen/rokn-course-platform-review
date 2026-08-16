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
        Schema::table('classifications', function (Blueprint $table): void {
            $table->boolean('show_on_home')->default(false)->after('name_en');
            $table->unsignedSmallInteger('home_order')->default(100)->after('show_on_home');
            $table->index(['show_on_home', 'home_order'], 'classifications_home_rows');
        });

        // Preserve the catalogue that was visible before home-row controls
        // existed. Administrators can curate or hide these rows afterwards;
        // a deployment must never turn a populated home page into an empty
        // one merely because the new opt-in column started at false.
        DB::table('classifications')->update(['show_on_home' => true]);

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedSmallInteger('home_sort_order')->default(100)->after('is_catalog_visible');
            $table->string('catalog_badge_ar', 40)->nullable()->after('home_sort_order');
            $table->string('catalog_badge_en', 40)->nullable()->after('catalog_badge_ar');
            $table->string('catalog_badge_tone', 16)->nullable()->after('catalog_badge_en');
            $table->index(['is_main_course', 'home_sort_order'], 'courses_home_merchandising');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropIndex('courses_home_merchandising');
            $table->dropColumn([
                'home_sort_order',
                'catalog_badge_ar',
                'catalog_badge_en',
                'catalog_badge_tone',
            ]);
        });

        Schema::table('classifications', function (Blueprint $table): void {
            $table->dropIndex('classifications_home_rows');
            $table->dropColumn(['show_on_home', 'home_order']);
        });
    }
};
