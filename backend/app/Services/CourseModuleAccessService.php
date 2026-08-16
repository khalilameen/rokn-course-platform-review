<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Setting;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use Illuminate\Support\Facades\URL;

final class CourseModuleAccessService
{
    public function __construct(private FinancialProvenanceService $provenance)
    {
    }

    public function hasCourseAccess(User $user, Course $course): bool
    {
        if (!$user->exists || !(bool) $user->active || $user->trashed()) {
            return false;
        }

        $active = static fn ($query) => $query
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        $direct = $active(CourseEnrollment::query()->where('course_id', $course->id))->get();
        foreach ($direct as $enrollment) {
            if (!$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])) {
                return true;
            }
        }

        $parentIds = CourseSection::query()
            ->where('sectionable_type', Course::class)
            ->where('sectionable_id', $course->id)
            ->pluck('course_id');

        if ($parentIds->isEmpty()) {
            return false;
        }

        foreach ($active(CourseEnrollment::query()->whereIn('course_id', $parentIds))->get() as $enrollment) {
            if (!$this->provenance->enrollmentHasActiveHold($enrollment, ['course'])) {
                return true;
            }
        }

        return false;
    }

    public function canAccessModule(User $user, Course $course, CourseModule $module): bool
    {
        if ((int) $module->course_id !== (int) $course->id || !$this->hasCourseAccess($user, $course)) {
            return false;
        }

        if (!(bool) (Setting::query()->value('enforce_course_section_order') ?? true)) {
            return true;
        }

        $sections = $course->sections()
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'module_id', 'section_type', 'sectionable_type', 'sectionable_id', 'order'])
            ->values();
        $targetIndex = $sections->search(fn (CourseSection $section) => (int) $section->module_id === (int) $module->id);

        if ($targetIndex === false) {
            return false;
        }
        if ($targetIndex === 0 || (int) $sections[$targetIndex]->order === 1) {
            return true;
        }

        $previous = $sections[$targetIndex - 1];
        if (!StudentSectionProgress::query()
            ->where('user_id', $user->id)
            ->where('course_section_id', $previous->id)
            ->where('is_completed', true)
            ->exists()) {
            return false;
        }

        if ((int) $previous->module_id !== (int) $module->id && $previous->module_id) {
            $project = $sections->first(fn (CourseSection $section) =>
                (int) $section->module_id === (int) $previous->module_id
                && $section->getSectionType() === 'project'
            );

            if ($project && !UserProjectEvaluation::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->sectionable_id)
                ->where('passed', true)
                ->exists()) {
                return false;
            }
        }

        return true;
    }

    public function canDownload(User $user, Course $course, CourseModule $module, Attachment $attachment): bool
    {
        return $attachment->attachable_type === CourseModule::class
            && (int) $attachment->attachable_id === (int) $module->id
            && $this->canAccessModule($user, $course, $module);
    }

    public function temporaryDownloadUrl(User $user, Course $course, CourseModule $module, Attachment $attachment): string
    {
        $minutes = max(1, min(30, (int) config('course_attachments.signed_url_minutes', 30)));

        return URL::temporarySignedRoute(
            'api.course-module-attachments.download',
            now()->addMinutes($minutes),
            [
                'course' => $course->getRouteKey(),
                'module' => $module->getRouteKey(),
                'attachment' => $attachment->getRouteKey(),
                'uid' => $user->getKey(),
            ]
        );
    }
}
