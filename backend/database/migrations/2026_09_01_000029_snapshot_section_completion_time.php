<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('student_section_progress')) {
            return;
        }

        if (!Schema::hasColumn('student_section_progress', 'completed_at')) {
            Schema::table('student_section_progress', fn (Blueprint $table) =>
                $table->timestamp('completed_at')->nullable()->after('is_completed')
            );
        }

        DB::table('student_section_progress')
            ->where('is_completed', true)
            ->whereNull('completed_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('student_section_progress')
                        ->where('id', $row->id)
                        ->whereNull('completed_at')
                        ->update(['completed_at' => $row->updated_at ?: $row->created_at]);
                }
            });

        if (!Schema::hasIndex('student_section_progress', 'student_section_progress_streak_lookup')) {
            Schema::table('student_section_progress', fn (Blueprint $table) =>
                $table->index(
                    ['user_id', 'is_completed', 'completed_at'],
                    'student_section_progress_streak_lookup'
                )
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('student_section_progress')) {
            return;
        }

        Schema::table('student_section_progress', function (Blueprint $table): void {
            if (Schema::hasIndex('student_section_progress', 'student_section_progress_streak_lookup')) {
                $table->dropIndex('student_section_progress_streak_lookup');
            }
            if (Schema::hasColumn('student_section_progress', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
