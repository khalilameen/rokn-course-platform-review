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
        $this->addIndex('courses', ['parent_id', 'is_coming_soon', 'is_catalog_visible', 'created_at', 'id'], 'courses_public_discovery_v2');
        $this->addIndex('course_enrollments', ['course_id', 'is_active', 'expires_at', 'user_id'], 'course_enrollments_course_active_v2');
        $this->addIndex('course_ratings', ['course_id', 'deleted_at', 'rating'], 'course_ratings_public_aggregate_v2');
        $this->addIndex('saved_folders', ['user_id', 'created_at', 'id'], 'saved_folders_user_timeline_v2');
        $this->addIndex('saved_folder_lessons', ['saved_folder_id', 'created_at', 'id'], 'saved_folder_lessons_timeline_v2');
        $this->addIndex('saved_folder_lessons', ['lesson_id', 'saved_folder_id'], 'saved_folder_lessons_lesson_lookup_v2');
        $this->addIndex('student_notifications', ['user_id', 'created_at', 'id'], 'student_notifications_user_timeline_v2');
        $this->addIndex('playback_sessions', ['user_id', 'lesson_id', 'ended_at', 'last_heartbeat_at', 'last_sequence', 'started_at'], 'playback_sessions_manifest_reuse_v2');
        $this->addIndex('packages', ['is_active', 'coins', 'id'], 'packages_public_catalog_v2');
        $this->addIndex('orders', ['payment_method', 'status', 'financial_status', 'reversed_at', 'approved_at', 'package_id'], 'orders_channel_dashboard_v2');
        $this->addIndex('financial_entitlement_holds', ['user_id', 'course_id', 'status', 'entitlement_scope', 'course_order_id'], 'financial_holds_runtime_access_v2');
    }

    public function down(): void
    {
        Schema::table('financial_entitlement_holds', fn (Blueprint $table) =>
            $table->dropIndex('financial_holds_runtime_access_v2')
        );
        Schema::table('orders', fn (Blueprint $table) =>
            $table->dropIndex('orders_channel_dashboard_v2')
        );
        Schema::table('packages', fn (Blueprint $table) =>
            $table->dropIndex('packages_public_catalog_v2')
        );
        Schema::table('playback_sessions', fn (Blueprint $table) =>
            $table->dropIndex('playback_sessions_manifest_reuse_v2')
        );
        Schema::table('student_notifications', fn (Blueprint $table) =>
            $table->dropIndex('student_notifications_user_timeline_v2')
        );
        Schema::table('saved_folder_lessons', function (Blueprint $table): void {
            $table->dropIndex('saved_folder_lessons_timeline_v2');
            $table->dropIndex('saved_folder_lessons_lesson_lookup_v2');
        });
        Schema::table('saved_folders', fn (Blueprint $table) =>
            $table->dropIndex('saved_folders_user_timeline_v2')
        );
        Schema::table('course_ratings', fn (Blueprint $table) =>
            $table->dropIndex('course_ratings_public_aggregate_v2')
        );
        Schema::table('course_enrollments', fn (Blueprint $table) =>
            $table->dropIndex('course_enrollments_course_active_v2')
        );
        Schema::table('courses', fn (Blueprint $table) =>
            $table->dropIndex('courses_public_discovery_v2')
        );
    }

    /** @param list<string> $columns */
    private function addIndex(string $tableName, array $columns, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) =>
            $table->index($columns, $indexName)
        );
    }
};
