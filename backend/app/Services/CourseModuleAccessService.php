<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\Setting;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

final class CourseModuleAccessService
{
    public function __construct(
        private CourseChatAccessService $courseAccess,
        private CourseSectionSequenceService $sectionSequence,
        private CourseRevisionLearnerReadService $revisionReads
    )
    {
    }

    public function hasCourseAccess(User $user, Course $course): bool
    {
        if (
            !$user->exists
            || !(bool) $user->active
            || $user->trashed()
            || !$course->isPublishedForLearning()
        ) {
            return false;
        }

        return $this->courseAccess->hasLearningAccess(
            (int) $user->id,
            (int) $course->id
        );
    }

    public function canAccessModule(User $user, Course $course, CourseModule $module): bool
    {
        if ((int) $module->course_id !== (int) $course->id || !$this->hasCourseAccess($user, $course)) {
            return false;
        }

        if (!(bool) (Setting::query()->value('enforce_course_section_order') ?? true)) {
            return true;
        }

        $sections = $this->sectionSequence->learning(
            $course->sections()
                ->get(['id', 'module_id', 'section_type', 'sectionable_type', 'sectionable_id', 'order'])
        );
        $targetIndex = $sections->search(fn (CourseSection $section) => (int) $section->module_id === (int) $module->id);

        if ($targetIndex === false) {
            return false;
        }
        if ($targetIndex === 0) {
            return true;
        }

        $previous = $sections[$targetIndex - 1];
        if (!$this->revisionReads->completedSectionIds(
            (int) $user->id,
            [(int) $previous->id]
        )->contains((int) $previous->id)) {
            return false;
        }

        if ((int) $previous->module_id !== (int) $module->id && $previous->module_id) {
            $projects = $sections->filter(fn (CourseSection $section) =>
                (int) $section->module_id === (int) $previous->module_id
                && $section->getSectionType() === 'project'
            );

            if ($projects->isNotEmpty()) {
                $projectIds = $projects->pluck('sectionable_id')->map(
                    static fn ($id): int => (int) $id
                )->values();
                $passedProjectIds = $this->revisionReads->passedProjectIds(
                    (int) $user->id,
                    $projectIds
                );
                if ($projectIds->contains(
                    fn (int $projectId): bool => !$passedProjectIds->contains($projectId)
                )) {
                    return false;
                }
            }
        }

        return true;
    }

    public function canDownload(User $user, Course $course, CourseModule $module, Attachment $attachment): bool
    {
        if (!$this->canAccessModule($user, $course, $module)) {
            return false;
        }
        if (
            $attachment->attachable_type === CourseModule::class
            && (int) $attachment->attachable_id === (int) $module->id
        ) {
            return true;
        }
        if ($attachment->attachable_type !== CourseSection::class) {
            return false;
        }

        $section = CourseSection::query()
            ->whereKey($attachment->attachable_id)
            ->where('course_id', $course->id)
            ->where('module_id', $module->id)
            ->first();

        return $section ? $this->canAccessSection($user, $course, $section) : false;
    }

    private function canAccessSection(User $user, Course $course, CourseSection $target): bool
    {
        $sections = $this->sectionSequence->learning(
            $course->sections()
                ->get(['id', 'module_id', 'section_type', 'sectionable_type', 'sectionable_id', 'order'])
        )->values();
        $targetIndex = $sections->search(
            fn (CourseSection $section): bool => (int) $section->id === (int) $target->id
        );
        if ($targetIndex === false || $targetIndex === 0) {
            return $targetIndex === 0;
        }

        $previous = $sections[$targetIndex - 1];

        return $this->revisionReads->completedSectionIds(
            (int) $user->id,
            [(int) $previous->id]
        )->contains((int) $previous->id);
    }

    public function temporaryDownloadUrl(User $user, Course $course, CourseModule $module, Attachment $attachment): string
    {
        $minutes = $this->downloadUrlMinutes();

        return URL::temporarySignedRoute(
            'api.course-module-attachments.download',
            now()->addMinutes($minutes),
            [
                'course' => $course->getKey(),
                'module' => $module->getKey(),
                'attachment' => $attachment->getKey(),
                'owner' => $this->ownerClaim($user),
            ]
        );
    }

    public function canDownloadPdf(User $user, Course $course, CoursePdf $pdf): bool
    {
        return (int) $pdf->course_id === (int) $course->id
            && (bool) $pdf->is_active
            && $this->hasCourseAccess($user, $course);
    }

    public function temporaryPdfDownloadUrl(User $user, Course $course, CoursePdf $pdf): string
    {
        $minutes = $this->downloadUrlMinutes();

        return URL::temporarySignedRoute(
            'api.course-pdfs.download',
            now()->addMinutes($minutes),
            [
                'course' => $course->getKey(),
                'pdf' => $pdf->getKey(),
                'owner' => $this->ownerClaim($user),
            ]
        );
    }

    /** Resolve the opaque owner bound into a validated signed download URL. */
    public function userFromSignedDownloadRequest(Request $request): ?User
    {
        $claim = $request->query('owner');
        if (!is_string($claim) || $claim === '') {
            return null;
        }

        try {
            $userId = filter_var(Crypt::decryptString($claim), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
        } catch (DecryptException) {
            return null;
        }

        return $userId === false ? null : User::query()->find($userId);
    }

    private function ownerClaim(User $user): string
    {
        return Crypt::encryptString((string) $user->getKey());
    }

    private function downloadUrlMinutes(): int
    {
        return max(5, min(60, (int) config('course_attachments.signed_url_minutes', 30)));
    }
}
