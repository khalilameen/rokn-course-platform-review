<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Models\CourseSection;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Project;
use App\Models\StudentSectionProgress;
use App\Models\UserProjectEvaluation;
use App\Services\CertificateService;
use App\Services\CourseChatAccessService;
use App\Services\FinancialProvenanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class GenerateCourseCertificate implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;
    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [15, 60, 300];

    public function __construct(
        private readonly CertificateService $certificates,
        private readonly CourseChatAccessService $courseAccess,
        private readonly FinancialProvenanceService $financialProvenance
    ) {
    }

    public function handle(CourseCompleted $event): void
    {
        try {
            $enrollment = CourseEnrollment::query()
                ->where('user_id', $event->user->id)
                ->where('course_id', $event->course->id)
                ->where('is_active', true)
                ->first();
            if (!$enrollment) {
                return;
            }
            if (!$this->courseAccess->hasCertificateAccess(
                (int) $event->user->id,
                (int) $event->course->id
            )) {
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

            $sectionIds = CourseSection::query()
                ->where('course_id', $event->course->id)
                ->pluck('id');

            if ($sectionIds->isEmpty()) {
                return;
            }

            $completedSections = StudentSectionProgress::query()
                ->where('user_id', $event->user->id)
                ->whereIn('course_section_id', $sectionIds)
                ->where('is_completed', true)
                ->distinct('course_section_id')
                ->count('course_section_id');

            // The event is only a signal; the listener remains the final
            // authority so a misplaced project or duplicate client call can
            // never issue a certificate before the whole course is complete.
            if ($completedSections !== $sectionIds->count()) {
                return;
            }

            // Resolve the project through the course-section ownership record.
            // Querying the inverse morph relation here made certificate
            // issuance depend on Eloquent inferring the legacy morph columns
            // correctly and was fragile across upgraded installations.
            $graduationProjectId = CourseSection::query()
                ->where('course_id', $event->course->id)
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
                $passed = UserProjectEvaluation::query()
                    ->where('user_id', $event->user->id)
                    ->where('project_id', $graduationProject->id)
                    ->where('passed', true)
                    ->exists();
                if (!$passed) {
                    return;
                }
            }

            $certificate = $this->certificates->generate(
                $event->user,
                $event->course,
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
