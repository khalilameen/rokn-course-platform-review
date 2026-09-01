<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::table('watching_logs')
            ->select('user_id', 'lesson_id')
            ->groupBy('user_id', 'lesson_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->orderBy('lesson_id')
            ->cursor()
            ->each(function ($duplicate): void {
                $rows = DB::table('watching_logs')
                    ->where('user_id', $duplicate->user_id)
                    ->where('lesson_id', $duplicate->lesson_id)
                    ->orderByRaw('COALESCE(watched_at, updated_at) DESC')
                    ->orderByDesc('id')
                    ->get();
                $keeper = $rows->first();
                if (!$keeper) return;
                $completedAt = $rows->pluck('completed_at')->filter()->sort()->first();
                if ($completedAt && !$keeper->completed_at) {
                    DB::table('watching_logs')->where('id', $keeper->id)->update([
                        'completed_at' => $completedAt,
                    ]);
                }
                DB::table('watching_logs')
                    ->whereIn('id', $rows->pluck('id')->reject(
                        fn ($id): bool => (int) $id === (int) $keeper->id
                    ))
                    ->delete();
            });

        $columns = [
            'playback_session_id' => fn (Blueprint $table) => $table->uuid('playback_session_id')->nullable()->after('duration_seconds'),
            'playback_session_started_at' => fn (Blueprint $table) => $table->timestamp('playback_session_started_at')->nullable()->after('playback_session_id'),
            'last_playback_sequence' => fn (Blueprint $table) => $table->unsignedInteger('last_playback_sequence')->nullable()->after('playback_session_started_at'),
        ];
        foreach ($columns as $column => $definition) {
            if (!Schema::hasColumn('watching_logs', $column)) {
                Schema::table('watching_logs', $definition);
            }
        }
        if (!Schema::hasIndex('watching_logs', 'watching_logs_user_lesson_unique')) {
            Schema::table('watching_logs', fn (Blueprint $table) =>
                $table->unique(['user_id', 'lesson_id'], 'watching_logs_user_lesson_unique')
            );
        }
    }

    public function down(): void
    {
        Schema::table('watching_logs', function (Blueprint $table): void {
            $table->dropUnique('watching_logs_user_lesson_unique');
            $table->dropColumn([
                'playback_session_id',
                'playback_session_started_at',
                'last_playback_sequence',
            ]);
        });
    }
};
