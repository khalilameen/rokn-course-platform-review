<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watching_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('course_section_id')->nullable()->after('course_id');
            $table->unsignedInteger('position_seconds')->default(0)->after('course_name');
            $table->unsignedInteger('duration_seconds')->nullable()->after('position_seconds');
            $table->timestamp('watched_at')->nullable()->after('duration_seconds');
            $table->timestamp('completed_at')->nullable()->after('watched_at');

            $table->index(['user_id', 'watched_at'], 'watching_logs_user_watched_index');
            $table->index(
                ['user_id', 'course_id', 'watched_at'],
                'watching_logs_user_course_watched_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('watching_logs', function (Blueprint $table) {
            $table->dropIndex('watching_logs_user_watched_index');
            $table->dropIndex('watching_logs_user_course_watched_index');
            $table->dropColumn([
                'course_section_id',
                'position_seconds',
                'duration_seconds',
                'watched_at',
                'completed_at',
            ]);
        });
    }
};
