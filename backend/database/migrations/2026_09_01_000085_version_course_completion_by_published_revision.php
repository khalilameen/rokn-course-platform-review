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
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            Schema::table('course_enrollments', function (Blueprint $table): void {
                $table->unsignedBigInteger('completed_curriculum_revision')->nullable()->index();
                $table->timestamp('curriculum_completed_at')->nullable();
            });
        }

        $revisions = DB::table('courses')
            ->select(['id', 'last_published_authoring_version', 'authoring_version'])
            ->get()
            ->mapWithKeys(static fn (object $course): array => [
                (int) $course->id => max(
                    1,
                    (int) ($course->last_published_authoring_version
                        ?: $course->authoring_version
                        ?: 1)
                ),
            ]);

        $backfillSignals = static function (bool $withExplicitRevision) use ($revisions): void {
            if (!Schema::hasTable('internal_signals')) {
                return;
            }

            DB::table('internal_signals')
                ->where('type', 'course.completed')
                ->select(['id', 'payload', 'created_at'])
                ->chunkById(500, function ($signals) use ($revisions, $withExplicitRevision): void {
                    foreach ($signals as $signal) {
                        $payload = is_array($signal->payload)
                            ? $signal->payload
                            : json_decode((string) $signal->payload, true);
                        if (!is_array($payload)) {
                            continue;
                        }

                        $userId = (int) ($payload['user_id'] ?? 0);
                        $courseId = (int) ($payload['course_id'] ?? 0);
                        if ($userId <= 0 || $courseId <= 0) {
                            continue;
                        }

                        $explicitRevision = (int) ($payload['curriculum_revision'] ?? 0);
                        if (($explicitRevision > 0) !== $withExplicitRevision) {
                            continue;
                        }
                        $revision = $explicitRevision > 0
                            ? $explicitRevision
                            : max(1, (int) ($revisions[$courseId] ?? 1));
                        DB::table('course_enrollments')
                            ->where('user_id', $userId)
                            ->where('course_id', $courseId)
                            ->whereNull('completed_curriculum_revision')
                            ->update([
                                'completed_curriculum_revision' => $revision,
                                'curriculum_completed_at' => $signal->created_at ?: now(),
                            ]);
                    }
                });
        };

        // A completion signal can carry the exact published revision earned by
        // the learner. Preserve it before falling back to today's course row.
        $backfillSignals(true);

        if (Schema::hasTable('certificates')) {
            DB::table('certificates')
                ->select(['id', 'user_id', 'course_id', 'generated_at', 'created_at'])
                ->chunkById(500, function ($certificates) use ($revisions): void {
                    foreach ($certificates as $certificate) {
                        $revision = (int) ($revisions[(int) $certificate->course_id] ?? 1);
                        DB::table('course_enrollments')
                            ->where('user_id', $certificate->user_id)
                            ->where('course_id', $certificate->course_id)
                            ->whereNull('completed_curriculum_revision')
                            ->update([
                                'completed_curriculum_revision' => $revision,
                                'curriculum_completed_at' => $certificate->generated_at
                                    ?: $certificate->created_at
                                    ?: now(),
                            ]);
                    }
                });
        }

        // Old signals did not include a revision. They remain a final fallback
        // for completed enrollments that never produced a certificate.
        $backfillSignals(false);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_curriculum_revision')) {
            return;
        }

        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->dropIndex(['completed_curriculum_revision']);
            $table->dropColumn([
                'completed_curriculum_revision',
                'curriculum_completed_at',
            ]);
        });
    }
};
