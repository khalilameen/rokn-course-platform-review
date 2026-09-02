<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Models\CourseSection;
use App\Models\CourseEnrollment;
use App\Models\Course;
use App\Models\Certificate;
use App\Models\Order;
use App\Models\Project;
use App\Models\User;
use App\Services\CertificateService;
use App\Services\CourseChatAccessService;
use App\Services\CourseSectionSequenceService;
use App\Services\CurriculumCompletionService;
use App\Services\FinancialProvenanceService;
use App\Services\CourseRevisionLearnerReadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class GenerateCourseCertificate implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;
    public int $tries = 3;
    public int $timeout = 120;
    public bool $failOnTimeout = true;
    public array $backoff = [15, 60, 300];

    public function viaQueue(): string
    {
        return (string) config('queue.channels.media', 'media');
    }

    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CourseChatAccessService $courseAccess,
        private readonly FinancialProvenanceService $financialProvenance,
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly CurriculumCompletionService $curriculumCompletion,
        private readonly CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    public function handle(CourseCompleted $event): void
    {
        try {
            $user = User::query()->find($event->resolvedUserId());
            $course = Course::query()->find($event->resolvedCourseId());
            if (!$user || !$course) {
                return;
            }

            $enrollment = CourseEnrollment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->where('is_active', true)
                ->first();
            if (!$enrollment) {
                return;
            }
            if (!$this->courseAccess->enrollmentHasCertificateAccess($enrollment)) {
                return;
            }
            if ($this->financialProvenance->enrollmentHasActiveHold($enrollment, ['course'])) {
                return;
            }
            if ($enrollment->order_id && Order::query()
                ->whereKey($enrollment->order_id)
                ->whereIn('financial_status', [
                    Order::FINANCIAL_PARTIALLY_RECOVERED,
                    Order::FINANCIAL_REVIEW_REQUIRED,
                ])
                ->where('unrecovered_coins', '>', 0)
                ->exists()) {
                return;
            }

            $earnedRevision = $this->curriculumCompletion->earnedRevision($enrollment);
            if ($earnedRevision === null) {
                $sections = CourseSection::query()
                    ->where('course_id', $course->id)
                    ->get();
                $sectionIds = $this->sectionSequence->learning($sections)->pluck('id');

                if ($sectionIds->isEmpty()) {
                    return;
                }

                $completedSections = $this->revisionReads
                    ->completedSectionIds((int) $user->id, $sectionIds)
                    ->count();

                // Rolling compatibility for a completion emitted before the
                // revision marker existed. Only current, complete evidence may
                // establish the one irreversible marker.
                if ($completedSections !== $sectionIds->count()) {
                    return;
                }
                $earnedRevision = $this->curriculumCompletion->markCompleted(
                    (int) $user->id,
                    (int) $course->id,
                    $event->resolvedCurriculumRevision()
                );
                if ($earnedRevision === null) {
                    return;
                }
            }

            // Resolve the project through the course-section ownership record.
            // Querying the inverse morph relation here made certificate
            // issuance depend on Eloquent inferring the legacy morph columns
            // correctly and was fragile across upgraded installations.
            $graduationProjectId = CourseSection::query()
                ->where('course_id', $course->id)
                ->where('section_type', 'project')
                ->where('sectionable_type', Project::class)
                ->whereIn('sectionable_id', Project::query()
                    ->where('is_graduation_project', true)
                    ->select('id'))
                ->orderBy('order')
                ->value('sectionable_id');

            $graduationProject = $graduationProjectId
                ? Project::query()->find($graduationProjectId)
                : null;

            if ($graduationProject) {
                $passed = $this->revisionReads->passedProjectIds(
                    (int) $user->id,
                    [(int) $graduationProject->id]
                )->contains((int) $graduationProject->id);
                if (!$passed) {
                    return;
                }
            }

            // Completion makes the certificate available; the learner first
            // confirms the exact immutable name from the certificates screen.
            // This listener only recovers an already-created pending artifact.
            if (!Certificate::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->exists()) {
                return;
            }

            $certificate = $this->certificates->generate(
                $user,
                $course,
                $graduationProject
            );

            if (!$certificate || $certificate->image_path === 'pending') {
                throw new \RuntimeException('Certificate generation did not produce an artifact.');
            }
        } catch (\Throwable $exception) {
            report($exception);

            // With the synchronous queue driver, re-throwing escapes through
            // the student's completion request and turns a successfully saved
            // lesson/project into a 500 response. The pending certificate row
            // is the durable recovery marker in that mode. Real queue workers
            // still receive the exception and apply the retry/backoff policy.
            if (config('queue.default') === 'sync') {
                return;
            }

            throw $exception;
        }
    }
}
