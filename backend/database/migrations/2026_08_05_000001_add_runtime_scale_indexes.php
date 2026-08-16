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
        if (Schema::hasTable('student_section_progress')) {
            // Historical code could race two firstOrNew/updateOrCreate calls.
            // Preserve a completed row while collapsing duplicates before the
            // database becomes the final idempotency authority.
            do {
                $duplicates = DB::table('student_section_progress')
                    ->select([
                        'user_id',
                        'course_section_id',
                        DB::raw('MIN(id) as keep_id'),
                        DB::raw('MAX(is_completed) as was_completed'),
                    ])
                    ->groupBy('user_id', 'course_section_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->limit(500)
                    ->get();

                foreach ($duplicates as $duplicate) {
                    DB::table('student_section_progress')
                        ->where('id', $duplicate->keep_id)
                        ->update(['is_completed' => (bool) $duplicate->was_completed]);
                    DB::table('student_section_progress')
                        ->where('user_id', $duplicate->user_id)
                        ->where('course_section_id', $duplicate->course_section_id)
                        ->where('id', '<>', $duplicate->keep_id)
                        ->delete();
                }
            } while ($duplicates->isNotEmpty());

            Schema::table('student_section_progress', function (Blueprint $table): void {
                $table->unique(
                    ['user_id', 'course_section_id'],
                    'student_section_progress_user_section_unique'
                );
                $table->index(
                    ['user_id', 'is_completed', 'course_section_id'],
                    'student_section_progress_completion_lookup'
                );
            });
        }

        if (Schema::hasTable('course_sections')) {
            Schema::table('course_sections', function (Blueprint $table): void {
                $table->index(
                    ['course_id', 'deleted_at', 'order'],
                    'course_sections_course_order_lookup'
                );
                $table->index(
                    ['module_id', 'deleted_at', 'order'],
                    'course_sections_module_order_lookup'
                );
            });
        }

        if (Schema::hasTable('student_notifications')) {
            Schema::table('student_notifications', function (Blueprint $table): void {
                $table->index(
                    ['user_id', 'is_read', 'created_at'],
                    'student_notifications_unread_timeline'
                );
                $table->index(
                    ['user_id', 'created_at'],
                    'student_notifications_user_timeline'
                );
            });
        }

        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->index(
                    ['is_coming_soon', 'parent_id', 'created_at'],
                    'courses_public_catalog_lookup'
                );
                $table->index(
                    ['is_coming_soon', 'is_main_course', 'created_at'],
                    'courses_home_rail_lookup'
                );
            });
        }

        if (Schema::hasTable('visitors')) {
            Schema::table('visitors', function (Blueprint $table): void {
                $table->index(['ip_address', 'visited_at'], 'visitors_daily_ip_lookup');
            });
        }

        if (Schema::hasTable('portfolio_items')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->index(
                    ['user_id', 'is_public', 'is_featured', 'sort_order', 'id'],
                    'portfolio_items_public_order_lookup'
                );
            });
        }

        if (Schema::hasTable('portfolio_media')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->index(
                    ['portfolio_item_id', 'sort_order', 'id'],
                    'portfolio_media_order_lookup'
                );
            });
        }

        if (Schema::hasTable('certificates')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->index(
                    ['user_id', 'status', 'revoked_at', 'generated_at'],
                    'certificates_public_timeline_lookup'
                );
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->index(
                    ['user_id', 'package_id', 'status', 'payment_method'],
                    'orders_pending_package_lookup'
                );
            });
        }

        $apiTokensTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($apiTokensTable)) {
            Schema::table($apiTokensTable, function (Blueprint $table): void {
                $table->index('expired_at', 'api_tokens_expired_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('student_section_progress')) {
            Schema::table('student_section_progress', function (Blueprint $table): void {
                $table->dropUnique('student_section_progress_user_section_unique');
                $table->dropIndex('student_section_progress_completion_lookup');
            });
        }
        if (Schema::hasTable('course_sections')) {
            Schema::table('course_sections', function (Blueprint $table): void {
                $table->dropIndex('course_sections_course_order_lookup');
                $table->dropIndex('course_sections_module_order_lookup');
            });
        }
        if (Schema::hasTable('student_notifications')) {
            Schema::table('student_notifications', function (Blueprint $table): void {
                $table->dropIndex('student_notifications_unread_timeline');
                $table->dropIndex('student_notifications_user_timeline');
            });
        }
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->dropIndex('courses_public_catalog_lookup');
                $table->dropIndex('courses_home_rail_lookup');
            });
        }
        if (Schema::hasTable('visitors')) {
            Schema::table('visitors', function (Blueprint $table): void {
                $table->dropIndex('visitors_daily_ip_lookup');
            });
        }
        if (Schema::hasTable('portfolio_items')) {
            Schema::table('portfolio_items', function (Blueprint $table): void {
                $table->dropIndex('portfolio_items_public_order_lookup');
            });
        }
        if (Schema::hasTable('portfolio_media')) {
            Schema::table('portfolio_media', function (Blueprint $table): void {
                $table->dropIndex('portfolio_media_order_lookup');
            });
        }
        if (Schema::hasTable('certificates')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->dropIndex('certificates_public_timeline_lookup');
            });
        }
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_pending_package_lookup');
            });
        }

        $apiTokensTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($apiTokensTable)) {
            Schema::table($apiTokensTable, function (Blueprint $table): void {
                $table->dropIndex('api_tokens_expired_at_index');
            });
        }
    }
};
