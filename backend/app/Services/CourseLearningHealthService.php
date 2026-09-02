<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\DB;

final class CourseLearningHealthService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence
    ) {
    }

    /**
     * Real learner progress for one course, aggregated without exposing student
     * identities to the course-authoring role.
     *
     * @return array{
     *   enrolled_students:int,
     *   started_students:int,
     *   completed_students:int,
     *   not_started_students:int,
     *   average_progress_percentage:int
     * }
     */
    public function forCourse(Course $course): array
    {
        $learningSectionIds = $this->sectionSequence
            ->learning($course->sections()->get([
                'id', 'course_id', 'module_id', 'section_type',
                'sectionable_type', 'order',
            ]))
            ->pluck('id')
            ->values();
        $sectionCount = $learningSectionIds->count();
        $activeStudents = DB::table('course_enrollments')
            ->join('users', 'users.id', '=', 'course_enrollments.user_id')
            ->select('course_enrollments.user_id')
            ->where('course_enrollments.course_id', $course->id)
            ->where('course_enrollments.is_active', true)
            ->whereNull('users.deleted_at')
            ->whereRaw('LOWER(users.role) = ?', ['client'])
            ->where(static function ($active): void {
                $active->whereNull('course_enrollments.expires_at')
                    ->orWhere('course_enrollments.expires_at', '>', now());
            })
            ->distinct();

        $completedByStudent = DB::table('student_section_progress as progress')
            ->whereIn('progress.course_section_id', $learningSectionIds)
            ->where('progress.is_completed', true)
            ->groupBy('progress.user_id')
            ->selectRaw('progress.user_id, COUNT(DISTINCT progress.course_section_id) as completed_sections');

        $row = DB::query()
            ->fromSub($activeStudents, 'students')
            ->leftJoinSub(
                $completedByStudent,
                'progress',
                'progress.user_id',
                '=',
                'students.user_id'
            )
            ->selectRaw('COUNT(*) as enrolled_students')
            ->selectRaw('SUM(CASE WHEN COALESCE(progress.completed_sections, 0) > 0 THEN 1 ELSE 0 END) as started_students')
            ->selectRaw('SUM(CASE WHEN COALESCE(progress.completed_sections, 0) = 0 THEN 1 ELSE 0 END) as not_started_students')
            ->selectRaw('AVG(COALESCE(progress.completed_sections, 0)) as average_completed_sections')
            ->when(
                $sectionCount > 0,
                fn ($query) => $query->selectRaw(
                    'SUM(CASE WHEN COALESCE(progress.completed_sections, 0) >= ? THEN 1 ELSE 0 END) as completed_students',
                    [$sectionCount]
                ),
                fn ($query) => $query->selectRaw('0 as completed_students')
            )
            ->first();

        $enrolled = (int) ($row->enrolled_students ?? 0);
        $averageCompleted = (float) ($row->average_completed_sections ?? 0);

        return [
            'enrolled_students' => $enrolled,
            'started_students' => min($enrolled, (int) ($row->started_students ?? 0)),
            'completed_students' => min($enrolled, (int) ($row->completed_students ?? 0)),
            'not_started_students' => min($enrolled, (int) ($row->not_started_students ?? 0)),
            'average_progress_percentage' => $sectionCount > 0
                ? min(100, max(0, (int) round(($averageCompleted / $sectionCount) * 100)))
                : 0,
        ];
    }
}
