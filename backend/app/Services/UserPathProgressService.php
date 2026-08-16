<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Level;
use App\Models\StudentSectionProgress;
use App\Models\User;

final readonly class UserPathProgressService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $enrollments = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('course', function ($courses): void {
                $courses->whereNotNull('path_id');
            })
            ->with([
                'course.coursePath',
                'course.level',
                'course.sections',
            ])
            ->get();

        $grouped = $enrollments->groupBy(function (CourseEnrollment $enrollment): mixed {
            return $enrollment->course->path_id;
        });
        $pathIds = $grouped->keys()->filter()->values()->all();
        $pathToLevelIds = collect();
        $levelsById = collect();

        if ($pathIds !== []) {
            $pathToLevelIds = Course::query()
                ->whereIn('path_id', $pathIds)
                ->whereNotNull('level_id')
                ->select('path_id', 'level_id')
                ->distinct()
                ->get()
                ->groupBy('path_id')
                ->map(function ($rows) {
                    return $rows->pluck('level_id')->values();
                });

            $allLevelIds = $pathToLevelIds->flatten()->unique()->values()->all();
            if ($allLevelIds !== []) {
                $levelsById = Level::query()
                    ->whereIn('id', $allLevelIds)
                    ->orderBy('order')
                    ->get()
                    ->keyBy('id');
            }
        }

        $data = [];
        foreach ($grouped as $pathId => $groupEnrollments) {
            $courses = $groupEnrollments->map->course;
            $path = $courses->first()?->coursePath;
            if ($path === null) {
                continue;
            }

            $currentLevel = $courses
                ->filter(function (Course $course): bool {
                    return $course->level_id !== null
                        && $course->relationLoaded('level')
                        && $course->level !== null;
                })
                ->sortByDesc(fn (Course $course): int => (int) ($course->level->order ?? 0))
                ->first()?->level;

            $sectionIds = $courses
                ->flatMap(fn (Course $course) => $course->sections->pluck('id'))
                ->unique()
                ->values();
            $totalSections = $sectionIds->count();
            $completedSections = $totalSections > 0
                ? StudentSectionProgress::query()
                    ->where('user_id', $user->id)
                    ->whereIn('course_section_id', $sectionIds->all())
                    ->where('is_completed', true)
                    ->count()
                : 0;
            $progressPercentage = $totalSections > 0
                ? round(($completedSections / $totalSections) * 100, 2)
                : 0.0;

            $levels = $pathToLevelIds
                ->get((int) $pathId, collect())
                ->map(fn ($id) => $levelsById->get($id))
                ->filter()
                ->sortBy(fn (Level $level) => $level->order)
                ->values()
                ->map(fn (Level $level): array => [
                    'id' => $level->id,
                    'name_ar' => $level->name_ar,
                    'name_en' => $level->name_en,
                    'badge_image_url' => $level->badge_image_url,
                    'order' => (int) $level->order,
                ])
                ->all();

            $data[] = [
                'path' => [
                    'id' => $path->id,
                    'title' => $path->title,
                    'title_ar' => $path->title_ar,
                    'title_en' => $path->title_en,
                ],
                'levels' => $levels,
                'current_level' => $currentLevel ? [
                    'id' => $currentLevel->id,
                    'name_ar' => $currentLevel->name_ar,
                    'name_en' => $currentLevel->name_en,
                    'badge_image_url' => $currentLevel->badge_image_url,
                    'order' => (int) $currentLevel->order,
                ] : null,
                'enrolled_courses_count' => $courses->count(),
                'total_sections' => $totalSections,
                'completed_sections' => $completedSections,
                'progress_percentage' => $progressPercentage,
            ];
        }

        return $data;
    }
}
