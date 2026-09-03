<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\GenerateProjectFeedback;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\ProjectSubmissionReviewDecision;
use App\Models\Certificate;
use App\Models\CourseSection;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\AiInputAttachment;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\DownloadFilename;
use App\Support\DurableJobDispatch;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;

final class ProjectSubmissionService
{
    public function __construct(
        private readonly CourseSectionSequenceService $sectionSequence,
        private readonly AiInputAttachmentService $attachments,
        private readonly StoredFileDeletionService $storedFiles,
        private readonly InternalSignalService $internalSignals,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CourseAccessPlanService $accessPlans,
        private readonly CourseStagedAuthoringService $stagedAuthoring,
        private readonly CourseRevisionLearnerReadService $revisionReads
    ) {
    }

    public function submit(
        User $user,
        Project $project,
        ?string $text,
        array|UploadedFile|null $files,
        string $idempotencyKey,
        array $metadata = []
    ): ProjectSubmission {
        $text = $text === null ? null : UnicodeText::clean($text);
        if ($text === '') $text = null;
        if ($text !== null && UnicodeText::graphemeLength($text) > 20000) {
            throw ValidationException::withMessages([
                'submission_text' => ['نص المشروع أطول من الحد المتاح'],
            ]);
        }
        $submissionDisk = (string) config('projects.submission_disk', 'local');
        if ($submissionDisk === '' || !is_array(config("filesystems.disks.{$submissionDisk}"))) {
            throw new \RuntimeException('The configured project submission disk is not available.');
        }

        // Keep the service boundary compatible with older callers that submit
        // one attachment while the API now supports a batch.
        $files = $files instanceof UploadedFile ? [$files] : ($files ?? []);
        $files = array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
        $requestFingerprint = $this->requestFingerprint($text, $files);
        $equivalentProjectIds = $this->stagedAuthoring->equivalentEntityIds(
            Project::class,
            (int) $project->id
        );
        $existing = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $equivalentProjectIds)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $files);
            return $this->finalizeIfDue($existing);
        }

        // A passed project is final, and a pending upload is resumed instead of duplicated.
        // This keeps retries/offline replays from ever locking the learner again.
        $activeSubmission = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $equivalentProjectIds)
            ->whereIn('review_status', [
                ProjectSubmission::STATUS_PENDING,
                ProjectSubmission::STATUS_PASSED,
            ])
            ->latest('id')
            ->first();
        if ($activeSubmission) {
            return $this->finalizeIfDue($activeSubmission);
        }

        $effortStatus = $this->detectEffort($text, $files);
        $storedPaths = [];
        $fileDescriptors = [];

        // Stage immutable, request-scoped object keys before taking any user
        // or database lock. The cleanup ledger commits before the first byte;
        // a process death or a losing concurrent request is therefore harmless.
        foreach ($files as $index => $file) {
            $sha = hash_file('sha256', $file->getRealPath());
            if (!is_string($sha) || $sha === '') {
                throw new \RuntimeException('The project attachment could not be fingerprinted.');
            }
            $storedPath = $this->storedFiles->storeTrackedUpload(
                $file,
                "project_submissions/{$user->id}/{$project->id}",
                $submissionDisk,
                60,
                implode('|', [
                    'project-submission', $user->id, $project->id,
                    strtolower($idempotencyKey), $index, $sha,
                ])
            );
            $storedPaths[] = $storedPath;
            $fileDescriptors[] = [
                'path' => $storedPath,
                'name' => DownloadFilename::safe(
                    $file->getClientOriginalName(),
                    'project-submission',
                    $file->guessExtension()
                ),
                'mime_type' => strtolower((string) $file->getMimeType()),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $sha,
                'storage_disk' => $submissionDisk,
            ];
        }

        try {
            $submission = DB::transaction(function () use (
                $user,
                $project,
                $text,
                $files,
                $storedPaths,
                $idempotencyKey,
                $metadata,
                $effortStatus,
                $requestFingerprint,
                $submissionDisk,
                $fileDescriptors,
                $equivalentProjectIds
            ): ProjectSubmission {
                // Different client retry keys are still serialized per learner,
                // preventing two simultaneous uploads for the same project.
                // Project and CourseSection are published catalog definitions;
                // locking either one would serialize every learner submitting
                // the same assignment. The mutable enrollment/submission state
                // below remains locked at its owning learner boundary.
                User::query()->where('active', true)->lockForUpdate()->findOrFail($user->id);
                $projectSnapshot = Project::query()->findOrFail($project->id);

                $existing = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->whereIn('project_id', $equivalentProjectIds)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $files);
                    return $existing;
                }

                $activeSubmission = ProjectSubmission::query()
                    ->where('user_id', $user->id)
                    ->whereIn('project_id', $equivalentProjectIds)
                    ->whereIn('review_status', [
                        ProjectSubmission::STATUS_PENDING,
                        ProjectSubmission::STATUS_PASSED,
                    ])
                    ->latest('id')
                    ->first();
                if ($activeSubmission) {
                    return $activeSubmission;
                }

                $primaryPath = $storedPaths[0] ?? null;
                $primaryDescriptor = $fileDescriptors[0] ?? null;
                $projectSection = CourseSection::query()
                    ->where('sectionable_type', Project::class)
                    ->where('sectionable_id', $projectSnapshot->id)
                    ->with('course:id,name_ar,name_en')
                    ->first();
                $enrollment = null;
                $accessTerms = null;
                if ($projectSection) {
                    $selectedEnrollment = $this->courseAccess->activeProjectEnrollmentFor(
                        (int) $user->id,
                        (int) $projectSection->course_id
                    );
                    if ($selectedEnrollment) {
                        $enrollment = CourseEnrollment::query()
                            ->whereKey($selectedEnrollment->id)
                            ->where('user_id', $user->id)
                            ->lockForUpdate()
                            ->first();
                        if (
                            $enrollment?->isActive()
                            && $this->courseAccess->hasLearningAccess(
                                (int) $user->id,
                                (int) $projectSection->course_id
                            )
                        ) {
                            $accessTerms = $this->accessPlans->termsForEnrollment($enrollment);
                        } else {
                            $enrollment = null;
                        }
                    }
                    if (!$enrollment) {
                        throw new AuthorizationException(
                            'The learner no longer has an active enrollment for this project.'
                        );
                    }
                }
                $evaluationSnapshot = ProjectSubmissionEvaluationSnapshot::capture(
                    $projectSnapshot,
                    $projectSection,
                    $enrollment,
                    $accessTerms
                );

                $isInvalid = $effortStatus === ProjectSubmission::EFFORT_INVALID;
                $reviewStatus = $isInvalid
                    ? ProjectSubmission::STATUS_NEEDS_RESUBMISSION
                    : ProjectSubmission::STATUS_PENDING;
                $feedback = $isInvalid
                    ? 'المحاولة غير واضحة بما يكفي للمراجعة. ارفع صورة أو ملفًا يوضح ما نفذته.'
                    : null;
                $submission = ProjectSubmission::create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'idempotency_key' => $idempotencyKey,
                    'submission_text' => $text,
                    'submission_file' => $primaryPath,
                    'original_file_name' => $primaryDescriptor['name'] ?? null,
                    'mime_type' => $primaryDescriptor['mime_type'] ?? null,
                    'file_size' => $primaryDescriptor['size_bytes'] ?? null,
                    'submission_metadata' => array_merge($metadata, [
                        'request_fingerprint' => $requestFingerprint,
                        'upload_session_id' => $idempotencyKey,
                        'object_key' => $primaryPath,
                        'checksum_sha256' => $primaryDescriptor
                            ? $primaryDescriptor['sha256']
                            : hash('sha256', trim((string) $text)),
                        'files' => $fileDescriptors,
                        'upload_finalized_at' => now()->toIso8601String(),
                        // Persist the exact private disk with the row. Changing
                        // PROJECT_SUBMISSION_DISK later must not orphan uploads
                        // created by an older web or queue node.
                        'storage_disk' => $submissionDisk,
                    ]),
                    'evaluation_snapshot' => $evaluationSnapshot,
                    'effort_status' => $effortStatus,
                    // Empty/black/solid attempts are the only immediate stop.
                    // A sincere attempt keeps the short reviewing state and then passes.
                    'review_status' => $reviewStatus,
                    'review_source' => $isInvalid ? 'effort_guard' : null,
                    'score' => $isInvalid ? 0 : null,
                    'feedback' => $feedback,
                    'submitted_at' => now(),
                    'auto_pass_at' => $isInvalid
                        ? null
                        : now()->addSeconds(max(1, (int) (
                            $projectSnapshot->fallback_review_delay_seconds
                            ?? config('projects.fallback_review_delay_seconds', 8)
                        ))),
                    'reviewed_at' => $isInvalid ? now() : null,
                ]);

                $decision = null;
                if ($isInvalid) {
                    $decision = $this->appendReviewDecision(
                        $submission,
                        $reviewStatus,
                        0,
                        (string) $feedback,
                        'effort_guard',
                        null,
                        [
                            'assessment_type' => 'effort_guard',
                            'skill_verified' => false,
                            'progression_credit' => false,
                        ]
                    );
                    $submissionMetadata = (array) $submission->submission_metadata;
                    $submissionMetadata['review_history'] = [
                        $this->decisionHistoryEntry($decision),
                    ];
                    $submission->forceFill([
                        'review_status' => $decision->status,
                        'review_source' => $decision->source,
                        'score' => $decision->score,
                        'feedback' => $decision->feedback,
                        'reviewed_at' => $decision->decided_at,
                        'reviewed_by' => $decision->reviewer_id,
                        'submission_metadata' => $submissionMetadata,
                    ])->save();
                }

                if ($files !== []) {
                    $courseId = $projectSection?->course_id;
                    $course = $courseId ? Course::query()->find($courseId) : null;
                    if ($course) {
                        foreach ((array) data_get($submission->submission_metadata, 'files', []) as $index => $stored) {
                            $this->attachments->registerStored(
                                $user, $course, (string) $stored['path'], $submissionDisk,
                                (string) $stored['name'], (string) $stored['mime_type'],
                                (int) $stored['size_bytes'], (string) $stored['sha256'],
                                $this->deterministicUploadId(
                                    implode('|', [
                                        'project-ai-input', $user->id, $projectSnapshot->id,
                                        strtolower($idempotencyKey), $index, (string) $stored['sha256'],
                                    ])
                                ),
                                (int) $submission->id
                            );
                        }
                    }
                }

                // Legacy summary remains available to existing mobile/API consumers,
                // but no score or pass decision is accepted from the client.
                UserProjectEvaluation::updateOrCreate(
                    ['user_id' => $user->id, 'project_id' => $project->id],
                    [
                        'score' => 0,
                        'passed' => false,
                        'evaluation_data' => [
                            'status' => $decision?->status ?? $reviewStatus,
                            'submission_id' => $submission->public_id,
                            'source' => $decision?->source
                                ?? ($isInvalid ? 'effort_guard' : 'server_review_policy'),
                            'decision_id' => $decision?->decision_id,
                            'decision_sequence' => $decision?->sequence,
                        ],
                        'submission_text' => $text,
                        'submission_file' => $primaryPath,
                    ]
                );

                return $submission;
            });

            return $this->finalizeIfDue($submission);
        } catch (QueryException $exception) {
            $existing = ProjectSubmission::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->assertIdempotentReplay($existing, $requestFingerprint, $text, $files);
                return $this->finalizeIfDue($existing);
            }

            throw $exception;
        }
    }

    public function finalizeIfDue(ProjectSubmission $submission): ProjectSubmission
    {
        if (
            $submission->review_status !== ProjectSubmission::STATUS_PENDING
            || !$submission->auto_pass_at
            || $submission->auto_pass_at->isFuture()
        ) {
            return $submission;
        }

        $result = DB::transaction(function () use ($submission): ProjectSubmission {
            // Account deletion owns the learner row before scrubbing this
            // aggregate. Taking the same owner lock first prevents a delayed
            // fallback job from recreating review/progress data afterwards.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner) {
                return $submission->fresh();
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PENDING) {
                return $locked;
            }

            $wasAlreadyPassed = $this->hasPassedProject(
                (int) $locked->user_id,
                (int) $locked->project_id
            );
            $passed = $wasAlreadyPassed
                || $locked->effort_status !== ProjectSubmission::EFFORT_INVALID;
            $feedback = $passed
                ? 'استلمنا محاولة واضحة وفتحنا لك المقطع التالي\nهذا قبول للاستكمال وليس تقييمًا للعمل'
                : 'المحاولة غير واضحة بما يكفي للمراجعة. ارفع صورة أو ملفًا يوضح ما نفذته.';

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'graceful_fallback',
                $feedback
            );
        });

        if ($result->review_status === ProjectSubmission::STATUS_PASSED) {
            // Feedback is a paid enhancement, never a gate. Queue/provider
            // failures cannot revoke the already granted progression.
            $this->queueFeedback((int) $result->id);
        }

        return $result;
    }

    public function reviewByAdmin(
        ProjectSubmission $submission,
        User $reviewer,
        bool $passed,
        ?string $feedback = null
    ): ProjectSubmission {
        if (!(bool) $reviewer->active
            || !in_array(Str::lower((string) $reviewer->role), ['admin', 'moderator'], true)) {
            throw new AuthorizationException('Only an active content reviewer can review project submissions.');
        }

        $reviewed = DB::transaction(function () use ($submission, $reviewer, $passed, $feedback): ProjectSubmission {
            // Serialize a human decision with account deletion at the same
            // aggregate-owner boundary used by learner submission. A form
            // left open for a deleted account must not restore scrubbed text,
            // progress or feedback records.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner) {
                throw ValidationException::withMessages([
                    'submission' => ['تم حذف حساب الطالب، لذلك لم يُسجل قرار جديد.'],
                ]);
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            $isGracefulFallback = $locked->review_status === ProjectSubmission::STATUS_PASSED
                && $locked->review_source === 'graceful_fallback';
            if (
                $locked->review_status !== ProjectSubmission::STATUS_PENDING
                && !$isGracefulFallback
            ) {
                throw ValidationException::withMessages([
                    'submission' => ['تمت مراجعة هذه المحاولة بالفعل، لذلك لم يتغير القرار المسجل.'],
                ]);
            }

            $wasAlreadyPassed = $this->hasPassedProject(
                (int) $locked->user_id,
                (int) $locked->project_id
            );
            if (!$passed && $wasAlreadyPassed) {
                throw ValidationException::withMessages([
                    'submission' => ['لا يمكن سحب حق الطالب في الاستكمال بعد قبوله تلقائيًا. يمكن اعتماد جودة العمل يدويًا عند القبول.'],
                ]);
            }

            $reviewFeedback = trim((string) $feedback);
            if ($reviewFeedback === '') {
                $reviewFeedback = $passed
                    ? 'راجع فريق ركن المحاولة وقبلها.'
                    : 'راجع فريق ركن المحاولة وطلب إعادة إرسالها.';
            }

            return $this->applyReviewOutcome(
                $locked,
                $passed,
                'admin_manual',
                $reviewFeedback,
                $reviewer
            );
        });

        if ($reviewed->review_status === ProjectSubmission::STATUS_PASSED) {
            $this->queueFeedback((int) $reviewed->id);
        }

        return $reviewed;
    }

    private function applyReviewOutcome(
        ProjectSubmission $locked,
        bool $passed,
        string $source,
        string $feedback,
        ?User $reviewer = null
    ): ProjectSubmission {
        $status = $passed
            ? ProjectSubmission::STATUS_PASSED
            : ProjectSubmission::STATUS_NEEDS_RESUBMISSION;
        $isParticipationAcceptance = $passed && $source === 'graceful_fallback';
        // The forgiving fallback grants progression only. A numeric score is
        // evidence of assessment, so it is reserved for a human review.
        $score = $passed ? ($isParticipationAcceptance ? null : 100) : 0;
        $reviewedAt = now();
        $metadata = is_array($locked->submission_metadata)
            ? $locked->submission_metadata
            : [];
        $metadata['assessment_type'] = $isParticipationAcceptance
            ? 'participation'
            : ($source === 'admin_manual' ? 'human_review' : 'effort_guard');
        $metadata['skill_verified'] = $passed && $source === 'admin_manual';
        $metadata['progression_credit'] = $passed;
        if (
            $passed
            && data_get($metadata, 'ai_feedback.status') !== 'ready'
        ) {
            // Persist intent before the queue dispatch so a lost enqueue can
            // be recovered. The job terminally classifies pass-only plans.
            $metadata['ai_feedback'] = [
                'status' => 'queued',
                'queued_at' => $reviewedAt->toIso8601String(),
            ];
        }

        $decision = $this->appendReviewDecision(
            $locked,
            $status,
            $score,
            $feedback,
            $source,
            $reviewer,
            [
                'assessment_type' => $metadata['assessment_type'],
                'skill_verified' => $metadata['skill_verified'],
                'progression_credit' => $metadata['progression_credit'],
                'effort_status' => $locked->effort_status,
            ],
            $reviewedAt
        );
        $history = is_array($metadata['review_history'] ?? null)
            ? $metadata['review_history']
            : [];
        $history[] = $this->decisionHistoryEntry($decision);
        // The dashboard compatibility summary stays small. The complete,
        // immutable sequence lives in project_submission_review_decisions.
        $metadata['review_history'] = array_slice($history, -20);

        $locked->update([
            'review_status' => $decision->status,
            'review_source' => $decision->source,
            'score' => $decision->score,
            'feedback' => $decision->feedback,
            'reviewed_at' => $decision->decided_at,
            'reviewed_by' => $decision->reviewer_id,
            'submission_metadata' => $metadata,
        ]);

        $evaluationAttributes = [
                // This legacy summary column is not nullable on older
                // installations. Consumers must use assessment_type and
                // skill_verified before presenting it as a graded score.
                'score' => $decision->score ?? 0,
                'passed' => $decision->status === ProjectSubmission::STATUS_PASSED,
                'evaluation_data' => [
                    'status' => $decision->status,
                    'submission_id' => $locked->public_id,
                    'source' => $decision->source,
                    'effort_status' => $locked->effort_status,
                    'decision_id' => $decision->decision_id,
                    'decision_sequence' => $decision->sequence,
                    'reviewer_id' => $decision->reviewer_id,
                    'reviewer_role' => $decision->reviewer_role,
                    'assessment_type' => $metadata['assessment_type'],
                    'skill_verified' => $metadata['skill_verified'],
                    'progression_credit' => $metadata['progression_credit'],
                ],
                'submission_text' => $locked->submission_text,
                'submission_file' => $locked->submission_file,
            ];
        UserProjectEvaluation::updateOrCreate(
            ['user_id' => $locked->user_id, 'project_id' => $locked->project_id],
            $evaluationAttributes
        );

        $currentProjectId = $this->stagedAuthoring->currentEntityId(
            Project::class,
            (int) $locked->project_id
        );
        if ($currentProjectId) {
            // A mutable current-projection lets the learner continue, while
            // the submission, decision sequence and feedback remain attached
            // to the immutable archived project snapshot.
            UserProjectEvaluation::updateOrCreate(
                ['user_id' => $locked->user_id, 'project_id' => $currentProjectId],
                $evaluationAttributes
            );
        }

        if (!$passed) {
            return $locked->fresh();
        }

        $this->internalSignals->record(
            'project.passed.first_reward',
            "user:{$locked->user_id}:project:{$locked->project_id}",
            [
                'user_id' => (int) $locked->user_id,
                'project_id' => (int) $locked->project_id,
            ],
            ProjectSubmission::class,
            (int) $locked->id
        );

        $projectSection = CourseSection::query()
            ->where('sectionable_type', Project::class)
            ->where('sectionable_id', $currentProjectId ?: $locked->project_id)
            ->first();
        if (!$projectSection) {
            return $locked->fresh();
        }

        $completedAt = now();
        DB::table('student_section_progress')->insertOrIgnore([
            'user_id' => $locked->user_id,
            'course_section_id' => $projectSection->id,
            'is_completed' => true,
            'completed_at' => $completedAt,
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
        StudentSectionProgress::query()
            ->where('user_id', $locked->user_id)
            ->where('course_section_id', $projectSection->id)
            ->where('is_completed', false)
            ->update([
                'is_completed' => true,
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

        $course = $projectSection->course;
        if (!$course) {
            return $locked->fresh();
        }

        if (
            $passed
            && $source === 'admin_manual'
            && Schema::hasColumn('certificates', 'verification_level')
        ) {
            Certificate::query()
                ->where('user_id', $locked->user_id)
                ->where('course_id', $course->id)
                ->where('status', 'active')
                ->update(['verification_level' => 'reviewed_project']);
        }

        $courseSections = CourseSection::query()
            ->where('course_id', $course->id)
            ->get();
        $courseSectionIds = $this->sectionSequence->learning($courseSections)->pluck('id');
        $completedSections = $this->revisionReads
            ->completedSectionIds((int) $locked->user_id, $courseSectionIds)
            ->count();

        if ($courseSectionIds->isEmpty() || $completedSections !== $courseSectionIds->count()) {
            return $locked->fresh();
        }

        $this->internalSignals->record(
            'course.completed',
            "user:{$locked->user_id}:course:{$course->id}",
            ['user_id' => (int) $locked->user_id, 'course_id' => (int) $course->id],
            'course_enrollment',
            "{$locked->user_id}:{$course->id}"
        );

        return $locked->fresh();
    }

    private function queueFeedback(int $submissionId): void
    {
        try {
            DurableJobDispatch::afterCommit(new GenerateProjectFeedback($submissionId));
        } catch (\Throwable $exception) {
            // The committed queued marker is the durable handoff. Recovery
            // will enqueue it when the broker returns, so a passed project
            // must not look failed merely because that first enqueue failed.
            report($exception);
        }
    }

    /**
     * The submission row is locked by every caller, so sequence allocation and
     * the derived current summary commit atomically with this append-only row.
     *
     * @param array<string,mixed> $metadata
     */
    private function appendReviewDecision(
        ProjectSubmission $submission,
        string $status,
        ?int $score,
        string $feedback,
        string $source,
        ?User $reviewer,
        array $metadata,
        ?\Carbon\CarbonInterface $decidedAt = null
    ): ProjectSubmissionReviewDecision {
        $sequence = (int) ProjectSubmissionReviewDecision::query()
            ->where('submission_id', $submission->id)
            ->max('sequence') + 1;

        return ProjectSubmissionReviewDecision::query()->create([
            'decision_id' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'sequence' => $sequence,
            'status' => $status,
            'score' => $score,
            'feedback' => $feedback,
            'source' => $source,
            'reviewer_id' => $reviewer?->id,
            'reviewer_role' => $reviewer?->role,
            'decided_at' => $decidedAt ?? now(),
            'decision_metadata' => $metadata,
        ]);
    }

    /** @return array<string,mixed> */
    private function decisionHistoryEntry(ProjectSubmissionReviewDecision $decision): array
    {
        return [
            'decision_id' => $decision->decision_id,
            'sequence' => (int) $decision->sequence,
            'status' => $decision->status,
            'score' => $decision->score,
            'feedback' => $decision->feedback,
            'source' => $decision->source,
            'reviewer_id' => $decision->reviewer_id,
            'reviewer_role' => $decision->reviewer_role,
            'reviewed_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    public function finalizeDue(int $limit = 100): int
    {
        $count = 0;
        ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PENDING)
            ->whereNotNull('auto_pass_at')
            ->where('auto_pass_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ProjectSubmission $submission) use (&$count): void {
                $this->finalizeIfDue($submission);
                $count++;
            });

        return $count;
    }

    private function detectEffort(?string $text, array $files): string
    {
        $plainText = trim((string) $text);
        if ($files !== []) {
            foreach ($files as $file) {
                if ((int) $file->getSize() < (int) config('projects.minimum_file_bytes', 512)) {
                    continue;
                }
                if (!str_starts_with((string) $file->getMimeType(), 'image/') || !$this->isBlankImage($file)) {
                    return ProjectSubmission::EFFORT_VALID;
                }
            }
            if ($plainText === '') {
                return ProjectSubmission::EFFORT_INVALID;
            }
        }

        return mb_strlen($plainText) >= (int) config('projects.minimum_text_length', 10)
            ? ProjectSubmission::EFFORT_VALID
            : ProjectSubmission::EFFORT_INVALID;
    }

    private function requestFingerprint(?string $text, array $files): string
    {
        $fileFacts = [];
        foreach ($files as $file) {
            $fileHash = hash_file('sha256', $file->getRealPath());
            if (!is_string($fileHash)) {
                throw new \RuntimeException('Unable to fingerprint the project attachment.');
            }
            $fileFacts[] = [
                'sha256' => $fileHash,
                'size' => (int) $file->getSize(),
                'mime_type' => strtolower((string) $file->getMimeType()),
            ];
        }

        return hash('sha256', json_encode([
            'text' => trim((string) $text),
            'files' => $fileFacts,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertIdempotentReplay(
        ProjectSubmission $submission,
        string $fingerprint,
        ?string $text,
        array $files
    ): void {
        $storedFingerprint = (string) data_get(
            $submission->submission_metadata,
            'request_fingerprint',
            ''
        );
        $legacyMatches = trim((string) $submission->submission_text) === trim((string) $text)
            && count((array) data_get($submission->submission_metadata, 'files', [])) === count($files);

        if (
            ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint))
            || ($storedFingerprint === '' && !$legacyMatches)
        ) {
            throw new \UnexpectedValueException(
                'Project submission idempotency key was reused for different content.'
            );
        }
    }

    private function hasPassedProject(int $userId, int $projectId): bool
    {
        $currentProjectId = $this->stagedAuthoring
            ->currentLearnerEntityMap(Project::class, [$projectId])[$projectId] ?? $projectId;

        return $this->revisionReads
            ->passedProjectIds($userId, [$currentProjectId])
            ->contains($currentProjectId);
    }

    private function deterministicUploadId(string $identity): string
    {
        $hex = substr(hash('sha256', $identity), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function isBlankImage(UploadedFile $file): bool
    {
        // Missing image tooling is treated forgivingly, never as a student failure.
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        // Never decode an unexpectedly large image into PHP memory. Upload
        // validation limits the file bytes, while this guard also blocks tiny
        // compressed images with pathological dimensions (decompression bombs).
        $inspectionBytes = max(1, (int) config('projects.image_inspection_max_bytes', 8388608));
        if ((int) $file->getSize() > $inspectionBytes) {
            return false;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            return true;
        }
        $width = max(0, (int) ($dimensions[0] ?? 0));
        $height = max(0, (int) ($dimensions[1] ?? 0));
        $maximumPixels = max(1, (int) config('projects.image_inspection_max_pixels', 12000000));
        if ($width < 2 || $height < 2) {
            return true;
        }
        if ($width > intdiv($maximumPixels, $height)) {
            return false;
        }

        $contents = @file_get_contents($file->getRealPath());
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;
        if ($image === false) {
            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 2) {
            imagedestroy($image);
            return true;
        }

        $samples = 0;
        $dark = 0;
        $white = 0;
        $minimumLuminance = 255;
        $maximumLuminance = 0;
        $stepX = max(1, (int) floor($width / 20));
        $stepY = max(1, (int) floor($height / 20));
        $threshold = (int) config('projects.dark_image_threshold', 12);
        $whiteThreshold = (int) config('projects.white_image_threshold', 248);

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($image, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $luminance = (int) round(($red + $green + $blue) / 3);
                $samples++;
                $minimumLuminance = min($minimumLuminance, $luminance);
                $maximumLuminance = max($maximumLuminance, $luminance);
                if (max($red, $green, $blue) <= $threshold) {
                    $dark++;
                }
                if (min($red, $green, $blue) >= $whiteThreshold) {
                    $white++;
                }
            }
        }

        imagedestroy($image);

        if ($samples === 0) {
            return true;
        }

        return ($dark / $samples) >= (float) config('projects.dark_image_ratio', 0.97)
            || ($white / $samples) >= (float) config('projects.white_image_ratio', 0.985)
            || ($maximumLuminance - $minimumLuminance) <= (int) config('projects.solid_image_luminance_range', 3);
    }
}
