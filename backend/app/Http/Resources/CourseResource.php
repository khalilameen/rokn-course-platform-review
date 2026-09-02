<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\CourseModule;
use App\Services\BunnyService;
use App\Models\CourseRating;
use App\Models\ExamAttempt;
use Illuminate\Support\Collection;
use App\Services\CourseModuleAccessService;
use App\Services\CoursePresentationService;
use App\Services\CourseChatAccessService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseRatingEligibilityService;
use App\Services\CourseStagedAuthoringService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\SafeExternalUrl;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Models\ProjectSubmission;
use App\Models\AiInputAttachment;
use Illuminate\Support\Facades\URL;

class CourseResource extends BaseCourseResource
{
    private ?BunnyService $bunnyService = null;
    private array $fullSectionContentCache = [];
    private array $sectionLockCache = [];
    private Collection $sectionAccessStates;
    private Collection $orderedSections;
    private Collection $projectEvaluations;
    private Collection $projectSubmissions;
    private Collection $passedQuizAttempts;
    private string $projectFeedbackLevel = 'pass_only';
    private array $projectFeedbackContract = [
        'project_report_enabled' => false,
        'project_thread_reply_enabled' => false,
        'project_message_limit' => 0,
        'project_token_budget' => 0,
    ];
    private bool $learningContextProvided = false;
    private bool $projectReplyRiskAllowed = false;
    private Collection $resolvedCompletedSectionIds;
    private ?array $resolvedEntitlement = null;
    private ?CourseEnrollment $resolvedEnrollment = null;
    private ?User $dashboardPreviewUser = null;

    /** Reuse the access/progress work already performed by the details query. */
    public function withLearningContext(
        Collection $completedSectionIds,
        ?array $entitlement,
        ?CourseEnrollment $enrollment
    ): static {
        $this->learningContextProvided = true;
        $this->resolvedCompletedSectionIds = $completedSectionIds;
        $this->resolvedEntitlement = $entitlement;
        $this->resolvedEnrollment = $enrollment;

        return $this;
    }

    /**
     * Render the learner contract for an authenticated dashboard preview.
     *
     * This context exists only for this resource instance. It deliberately
     * starts with no learner progress and never creates an enrollment or
     * changes the course publication state.
     *
     * @param array<string, mixed> $planContract
     */
    public function withDashboardPreviewContext(User $actor, array $planContract): static
    {
        $this->dashboardPreviewUser = $actor;
        $this->projectFeedbackContract = $planContract;
        $this->projectReplyRiskAllowed = (bool) (
            $planContract['project_thread_reply_enabled'] ?? false
        );
        $this->projectFeedbackLevel = (string) (
            $planContract['project_feedback_level'] ?? 'pass_only'
        );

        return $this;
    }

    /**
     * Transform the resource into an array.
     * Full course resource with sensitive data and section lock status for authorized users
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $baseData = parent::toArray($request);

        // Get current user
        $user = $this->dashboardPreviewUser ?? auth('api')->user();
        // A dashboard actor supplies identity only so the entitled resource
        // follows the same branch as the app. Their own progress, attempts and
        // submissions must never leak into a fresh-student preview.
        $learnerStateUser = $this->dashboardPreviewUser ? null : $user;
        $sections = $this->relationLoaded('sections') ? $this->sections : collect();
        $completedSectionIds = $this->learningContextProvided
            ? $this->resolvedCompletedSectionIds
            : $this->getCompletedSectionIds($user, $sections);
        $projectIds = $sections
            ->filter(fn ($section) => $section->getSectionType() === 'project')
            ->pluck('sectionable_id')
            ->filter()
            ->unique()
            ->values();
        $revisionReads = app(CourseRevisionLearnerReadService::class);
        $this->projectEvaluations = ($learnerStateUser && $projectIds->isNotEmpty())
            ? $revisionReads->projectEvaluations((int) $learnerStateUser->id, $projectIds)
            : collect();
        if ($learnerStateUser && $projectIds->isNotEmpty()) {
            $stagedAuthoring = app(CourseStagedAuthoringService::class);
            $projectAliases = collect($stagedAuthoring->equivalentEntityMap(Project::class, $projectIds));
            $aliasToCurrent = $projectAliases->flatMap(fn (array $aliases, int $currentId) =>
                collect($aliases)->mapWithKeys(fn (int $alias): array => [$alias => $currentId])
            );
            $latestSubmissionIds = ProjectSubmission::query()
                ->selectRaw('MAX(id)')
                ->where('user_id', $learnerStateUser->id)
                ->whereIn('project_id', $aliasToCurrent->keys())
                ->groupBy('project_id');
            $submissions = ProjectSubmission::query()
                ->whereIn('id', $latestSubmissionIds)
                ->with(['aiInputAttachments', 'feedbackThread'])
                ->orderByDesc('id')->get();
            $this->projectSubmissions = collect();
            foreach ($submissions as $submission) {
                $currentId = (int) ($aliasToCurrent->get((int) $submission->project_id) ?? 0);
                if ($currentId > 0 && !$this->projectSubmissions->has($currentId)) {
                    $this->projectSubmissions->put($currentId, $submission);
                }
            }
        } else {
            $this->projectSubmissions = collect();
        }
        $quizIds = $sections
            ->filter(fn ($section) => $section->getSectionType() === 'quiz')
            ->pluck('sectionable_id')
            ->filter()
            ->unique()
            ->values();
        if ($learnerStateUser && $quizIds->isNotEmpty()) {
            $stagedAuthoring ??= app(CourseStagedAuthoringService::class);
            $quizSections = $sections->where('sectionable_type', \App\Models\ItemList::class);
            $quizAliasToCurrent = collect($stagedAuthoring->equivalentEntityMap(
                \App\Models\ItemList::class,
                $quizSections->pluck('sectionable_id')
            ))->flatMap(fn (array $aliases, int $currentId) =>
                collect($aliases)->mapWithKeys(fn (int $alias): array => [$alias => $currentId])
            );
            $sectionAliasToCurrent = collect($stagedAuthoring->equivalentEntityMap(
                \App\Models\CourseSection::class,
                $quizSections->pluck('id')
            ))->flatMap(fn (array $aliases, int $currentId) =>
                collect($aliases)->mapWithKeys(fn (int $alias): array => [$alias => $currentId])
            );
            $this->passedQuizAttempts = ExamAttempt::query()
                ->where('user_id', $learnerStateUser->id)
                ->where('course_id', $this->id)
                ->whereIn('quiz_id', $quizAliasToCurrent->keys())
                ->where('status', ExamAttempt::STATUS_COMPLETED)
                ->where('is_passed', true)
                ->get(['id', 'quiz_id', 'section_id', 'score_percentage'])
                ->each(function (ExamAttempt $attempt) use ($quizAliasToCurrent, $sectionAliasToCurrent): void {
                    $attempt->quiz_id = $quizAliasToCurrent->get((int) $attempt->quiz_id, $attempt->quiz_id);
                    if ($attempt->section_id !== null) {
                        $attempt->section_id = $sectionAliasToCurrent->get(
                            (int) $attempt->section_id,
                            $attempt->section_id
                        );
                    }
                });
        } else {
            $this->passedQuizAttempts = collect();
        }
        $this->sectionAccessStates = app(CoursePresentationService::class)
            ->sectionLockStatus(
                $sections,
                $completedSectionIds,
                $learnerStateUser ? (int) $learnerStateUser->id : null
            )
            ->keyBy('section_id');
        $sectionsById = $sections->keyBy('id');
        $this->orderedSections = $this->sectionAccessStates->keys()
            ->map(fn ($sectionId) => $sectionsById->get($sectionId))
            ->filter()
            ->values();

        // Check if user is enrolled in this course
        $enrollment = $this->learningContextProvided
            ? $this->resolvedEnrollment
            : null;
        if ($user) {
            if (!$this->learningContextProvided) {
                $enrollment = app(CourseChatAccessService::class)
                    ->activeEnrollmentFor((int) $user->id, (int) $this->id);
            }
            $this->projectFeedbackLevel = (string) (
                ($this->resolvedEntitlement
                    ?? app(CourseChatAccessService::class)
                        ->entitlementFor((int) $user->id, (int) $this->id))['project_feedback_level']
                ?? 'pass_only'
            );
            if ($enrollment) {
                $plans = app(CourseAccessPlanService::class);
                $terms = $plans->termsForEnrollment($enrollment);
                if ($terms) {
                    $this->projectFeedbackContract = $plans->publicPayloadFromTerms($terms);
                    $variableCostAllowed = app(CourseChatAccessService::class)
                        ->enrollmentAllowsVariableCostFeatures($enrollment);
                    $this->projectReplyRiskAllowed = (bool) (
                        $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                    ) && $variableCostAllowed;
                    $this->projectFeedbackLevel = $variableCostAllowed
                        ? (string) ($this->projectFeedbackContract['project_feedback_level'] ?? 'pass_only')
                        : 'pass_only';
                }
            }
            if ($this->dashboardPreviewUser) {
                $this->projectFeedbackLevel = (string) (
                    $this->projectFeedbackContract['project_feedback_level'] ?? 'pass_only'
                );
                $this->projectReplyRiskAllowed = (bool) (
                    $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                );
            }
        }

        $moduleAccess = app(CourseModuleAccessService::class);
        $hasCourseAccess = $user
            ? ($this->learningContextProvided
                ? (bool) ($this->resolvedEntitlement['has_learning_access'] ?? false)
                : $moduleAccess->hasCourseAccess($user, $this->resource))
            : false;

        $promptFrequency = (string) ($this->attachment_prompt_frequency
            ?: config('course_attachments.prompt.default_frequency', 'once_per_course'));
        if (!array_key_exists(
            $promptFrequency,
            (array) config('course_attachments.prompt.frequencies', [])
        )) {
            $promptFrequency = 'once_per_course';
        }
        $baseData['attachment_prompt'] = [
            'enabled' => $hasCourseAccess && (bool) $this->attachment_prompt_enabled,
            'at_seconds' => max(0, (int) (
                $this->attachment_prompt_at_seconds
                ?? config('course_attachments.prompt.at_seconds', 20)
            )),
            'title' => trim((string) $this->attachment_prompt_title)
                ?: (string) config('course_attachments.prompt.title'),
            'body' => trim((string) $this->attachment_prompt_body)
                ?: (string) config('course_attachments.prompt.body'),
            'button_text' => trim((string) $this->attachment_prompt_button_text)
                ?: (string) config('course_attachments.prompt.button_text'),
            'frequency' => $promptFrequency,
        ];

        // Add enrollment information
        $baseData['enrollment'] = $enrollment ? [
            'id' => $enrollment->id,
            'enrolled_at' => $enrollment->enrolled_at,
            'expires_at' => $enrollment->expires_at,
            'is_active' => $enrollment->isActive(),
            'access_granted_at' => $enrollment->access_granted_at
        ] : null;

        // One logical rating survives soft deletion so restoring it cannot
        // create a duplicate. Its version also protects edits from two devices.
        $userRating = null;
        $ratingEligibility = ['can_rate' => false, 'reason' => 'course_access_required'];
        if ($user) {
            $userRating = CourseRating::withTrashed()->where('user_id', $user->id)
                ->where('course_id', $this->id)
                ->first();
            $ratingEligibility = app(CourseRatingEligibilityService::class)
                ->for($user, $this->resource, $hasCourseAccess, $userRating !== null);
        }

        $baseData['user_rating'] = $userRating && !$userRating->trashed() ? [
            'id' => $userRating->id,
            'rating' => (int)$userRating->rating,
            'comment' => $userRating->comment,
            'version' => (int) $userRating->version,
            'created_at' => $userRating->created_at,
            'updated_at' => $userRating->updated_at,
        ] : null;
        $baseData['rating_eligibility'] = [
            'can_rate' => (bool) $ratingEligibility['can_rate'],
            'reason' => (string) $ratingEligibility['reason'],
            'version' => (int) ($userRating?->version ?? 0),
        ];
        $planAttachmentMax = max(0, (int) ($this->projectFeedbackContract['chat_attachment_max_files'] ?? 0));
        $courseAttachmentMax = min(5, max(1, (int) ($this->chat_attachment_max_files ?? 1)));
        $baseData['chat_attachments_enabled'] = $hasCourseAccess
            && (bool) $this->chat_attachments_enabled
            && (bool) ($this->projectFeedbackContract['chat_attachments_enabled'] ?? false)
            && $planAttachmentMax > 0;
        $baseData['chat_attachment_max_files'] = $baseData['chat_attachments_enabled']
            ? min($courseAttachmentMax, $planAttachmentMax)
            : 0;

        // Override sections with full content and lock status
        $baseData['sections'] = $this->whenLoaded('sections', function() {
            return $this->orderedSections->map(function($section) {
                $isLocked = $this->isSectionLockedFromState($section);
                $isPreview = $section->getSectionType() === 'lesson'
                    && (bool) ($section->sectionable?->is_opened ?? false);
                $sectionData = [
                    'id' => $section->id,
                    'content_id' => $section->sectionable_id,
                    'title' => $section->title,
                    'type' => $section->getSectionType(),
                    'order' => $section->order,
                    'module_id' => $section->module_id,
                    'is_preview' => $isPreview,
                    'is_locked' => $isLocked,
                ];

                // Never serialize playable URLs, project requirements, quiz
                // data or external links for a gated step. Explicit previews
                // remain playable by design, even when later in the map.
                if (!$isLocked || $isPreview) {
                    $sectionData['content'] = $this->getFullSectionContent($section);
                }

                return $sectionData;
            });
        });

        // Override modules with full content and lock status for sections
        $baseData['modules'] = $this->whenLoaded('modules', function() use ($hasCourseAccess, $moduleAccess, $user) {
            $coursePdfs = $this->relationLoaded('activePdfs') ? $this->activePdfs : collect();
            return $this->modules->sortBy([
                ['order', 'asc'],
                ['id', 'asc'],
            ])->values()->map(function($module) use ($hasCourseAccess, $moduleAccess, $user, $coursePdfs) {
                $moduleSections = $module->sections
                    ? $module->sections->sortBy('order')->values()
                    : collect();
                $firstSection = $moduleSections->first();
                $moduleIsLocked = !$firstSection || (
                    $this->isSectionLockedFromState($firstSection)
                    && !(
                        $firstSection->getSectionType() === 'lesson'
                        && (bool) ($firstSection->sectionable?->is_opened ?? false)
                    )
                );

                $moduleData = [
                    'id' => $module->id,
                    'title' => $module->title,
                    'attachment_platform' => $module->attachment_platform,
                    'order' => $module->order,
                    'is_locked' => $moduleIsLocked,
                    'attachments_count' => $module->attachments->count()
                        + $moduleSections->sum(fn ($section) => $section->attachments->count())
                        + $coursePdfs->count()
                        + (SafeExternalUrl::sanitize($module->attachments_link) !== null ? 1 : 0),
                    'sections' => $moduleSections->map(function($section) use ($hasCourseAccess, $moduleAccess, $user, $module) {
                        $isLocked = $this->isSectionLockedFromState($section);
                        $isPreview = $section->getSectionType() === 'lesson'
                            && (bool) ($section->sectionable?->is_opened ?? false);
                        $sectionData = [
                            'id' => $section->id,
                            'content_id' => $section->sectionable_id,
                            'title' => $section->title,
                            'type' => $section->getSectionType(),
                            'order' => $section->order,
                            'is_preview' => $isPreview,
                            'is_locked' => $isLocked,
                        ];

                        if (!$isLocked || $isPreview) {
                            $sectionData['content'] = $this->getFullSectionContent($section);
                        }
                        if ($hasCourseAccess && !$isLocked && $user) {
                            $sectionData['attachments'] = $section->attachments
                                ->map(fn ($attachment) => $this->attachmentPayload(
                                    $attachment,
                                    $moduleAccess,
                                    $user,
                                    $module
                                ))
                                ->values();
                        }

                        return $sectionData;
                    }),
                ];

                if ($hasCourseAccess && !$moduleIsLocked && $user) {
                    $moduleData['attachments_link'] = SafeExternalUrl::sanitize($module->attachments_link);
                    $moduleAttachments = $module->attachments->map(
                        fn ($attachment) => $this->attachmentPayload(
                            $attachment,
                            $moduleAccess,
                            $user,
                            $module
                        )
                    );
                    $pdfAttachments = $coursePdfs->map(function($pdf) use ($moduleAccess, $user) {
                        $expiresInSeconds = max(300, min(3600, (int) config('course_attachments.signed_url_minutes', 30) * 60));
                        return [
                            'id' => 'pdf-' . $pdf->id,
                            'title' => (string) $pdf->title,
                            'download_url' => $moduleAccess->temporaryPdfDownloadUrl($user, $this->resource, $pdf),
                            'expires_in_seconds' => $expiresInSeconds,
                            'download_url_expires_at' => now()->addSeconds($expiresInSeconds)->toIso8601String(),
                            'download_url_is_temporary' => true,
                            'file_type' => 'pdf',
                            'mime_type' => 'application/pdf',
                            'file_size_bytes' => (int) $pdf->file_size,
                            'file_size' => $pdf->formatted_file_size,
                            'download_version' => sha1(implode('|', [
                                $pdf->id,
                                $pdf->updated_at,
                                $pdf->file_path,
                                $pdf->file_size,
                            ])),
                        ];
                    });
                    $moduleData['attachments'] = $moduleAttachments
                        ->concat($pdfAttachments)
                        ->values();
                }

                return $moduleData;
            });
        });

        return $baseData;
    }

    /** Build one entitled, replace-aware download contract for module/section files. */
    private function attachmentPayload($attachment, $moduleAccess, $user, $module): array
    {
        $expiresInSeconds = max(
            300,
            min(3600, (int) config('course_attachments.signed_url_minutes', 30) * 60)
        );

        return [
            'id' => $attachment->id,
            'title' => $attachment->title,
            'download_url' => $moduleAccess->temporaryDownloadUrl(
                $user,
                $this->resource,
                $module,
                $attachment
            ),
            'expires_in_seconds' => $expiresInSeconds,
            'download_url_expires_at' => now()->addSeconds($expiresInSeconds)->toIso8601String(),
            'download_url_is_temporary' => true,
            'file_type' => $attachment->file_type,
            'mime_type' => $attachment->mime_type ?: 'application/octet-stream',
            'file_size_bytes' => (int) $attachment->file_size,
            'file_size' => $attachment->file_size_human,
            'download_version' => sha1(implode('|', [
                $attachment->id,
                $attachment->updated_at,
                $attachment->file_path,
                $attachment->file_size,
            ])),
        ];
    }

    /**
     * Get all completed section IDs for the user in a single query
     * This optimizes the N+1 query problem
     *
     * @param \App\Models\User|null $user
     * @return \Illuminate\Support\Collection
     */
    protected function getCompletedSectionIds($user, ?Collection $sections = null)
    {
        if (!$user) {
            return collect(); // No user = no completed sections
        }

        // Get all section IDs for this course
        $courseSectionIds = ($sections ?? $this->sections)->pluck('id');

        if ($courseSectionIds->isEmpty()) {
            return collect();
        }

        // Single query to get all completed sections for this user in this course
        return app(CourseRevisionLearnerReadService::class)->completedSectionIds(
            (int) $user->id,
            $courseSectionIds
        );
    }

    protected function isSectionLockedFromState($section): bool
    {
        $sectionId = (int) $section->id;
        if (array_key_exists($sectionId, $this->sectionLockCache)) {
            return $this->sectionLockCache[$sectionId];
        }

        return $this->sectionLockCache[$sectionId] = (bool) (
            $this->sectionAccessStates->get($sectionId)['is_locked'] ?? true
        );
    }

    /**
     * Get full section content including sensitive data
     *
     * @param \App\Models\CourseSection $section
     * @return array
     */
    protected function getFullSectionContent($section)
    {
        $sectionId = (int) $section->id;
        if (array_key_exists($sectionId, $this->fullSectionContentCache)) {
            return $this->fullSectionContentCache[$sectionId];
        }

        if (!$section->sectionable) {
            return null;
        }

        $content = [
            'id' => $section->sectionable->id,
            'title' => $section->sectionable->title ?? $section->sectionable->name_ar ?? null,
            'description' => $section->sectionable->description ?? $section->sectionable->description_ar ?? null,
        ];

        // Add type-specific full data (including sensitive data)
        switch ($section->getSectionType()) {
            case 'lesson':
                $bunnyService = $this->bunnyService ??= app(BunnyService::class);
                $isPreview = (bool) $section->sectionable->is_opened;
                // Paid media is issued only by the per-user playback manifest
                // endpoint. Keeping it out of the broad course payload limits
                // link reuse and makes session telemetry authoritative.
                $videoData = $isPreview
                    ? $bunnyService->getVideoDataForLesson($section->sectionable)
                    : [
                        'video_source_type' => 'bunny',
                        'video_link' => null,
                        'bunny_video_url' => null,
                        'bunny_video_expires_at' => null,
                    ];
                $fallbackVideo = $isPreview
                    && !empty($videoData['bunny_video_url'])
                    && $section->sectionable->bunny_video_id
                    ? $bunnyService->getFallbackVideo((string) $section->sectionable->bunny_video_id)
                    : null;

                $content['video_source_type'] = $videoData['video_source_type'];
                $content['video_link'] = $videoData['video_link'];
                $content['bunny_video_url'] = $videoData['bunny_video_url'];
                $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
                $content['fallback_video_url'] = $fallbackVideo['url'] ?? null;
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $durationSeconds = $this->lessonDurationSeconds($section->sectionable);
                $content['duration_minutes'] = $durationSeconds > 0
                    ? (int) ceil($durationSeconds / 60)
                    : max(0, (int) ($section->sectionable->duration_minutes ?? 0));
                $content['duration_seconds'] = $durationSeconds ?: null;
                $content['thumbnail_url'] = $section->sectionable->thumbnail_path
                    ? $bunnyService->generateBunnySignedUrl($section->sectionable->thumbnail_path)
                    : null;
                break;

                case 'project':
                    $content['requirements_text'] = $section->sectionable->requirements_text ?? null;
                    $content['passing_score'] = $section->sectionable->passing_score ?? null;
                    $content['is_graduation_project'] = $section->sectionable->is_graduation_project ?? false;
                    $content['submission_max_files'] = max(1, min(5, (int) (
                        $section->sectionable->submission_max_files ?: 3
                    )));
                    $content['submission_allowed_mime_types'] = (array) (
                        $section->sectionable->submission_allowed_mime_types
                        ?: app(\App\Services\AiInputAttachmentService::class)->allowedMimeTypes()
                    );
                    $evaluation = $this->projectEvaluations->get((int) $section->sectionable->id);
                    $submission = $this->projectSubmissions->get((int) $section->sectionable->id);
                    $content['status'] = data_get($evaluation?->evaluation_data, 'status')
                        ?: ($evaluation ? ($evaluation->passed ? 'passed' : 'pending') : 'not_submitted');
                    $content['passed'] = (bool) ($evaluation?->passed ?? false);
                    $content['latest_submission'] = $submission ? [
                        'id' => (string) $submission->public_id,
                        'status' => (string) $submission->review_status,
                        'passed' => $submission->review_status === ProjectSubmission::STATUS_PASSED,
                        'can_continue' => $submission->review_status === ProjectSubmission::STATUS_PASSED,
                        'needs_resubmission' => $submission->review_status === ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
                        'attachments' => $submission->aiInputAttachments->map(
                            static fn (AiInputAttachment $attachment): array => [
                                'id' => (string) $attachment->public_id,
                                'name' => (string) $attachment->original_file_name,
                                'mime_type' => (string) $attachment->mime_type,
                                'size_bytes' => (int) $attachment->size_bytes,
                                'download_url' => URL::temporarySignedRoute(
                                    'api.project-input-attachments.download',
                                    now()->addMinutes(30),
                                    [
                                        'attachment' => $attachment->public_id,
                                        'user' => $attachment->user_id,
                                    ]
                                ),
                                'download_url_expires_at' => now()->addMinutes(30)->toIso8601String(),
                            ]
                        )->values()->all(),
                        // The full transcript is deliberately fetched from
                        // the thread endpoint only when this project is open.
                        // Keeping the course payload to a summary avoids a
                        // query/message explosion on long course maps.
                        'feedback_thread' => $submission->feedbackThread ? [
                            'id' => (string) $submission->feedbackThread->public_id,
                            'feedback_level' => $this->projectFeedbackLevel,
                            'can_reply' => $submission->feedbackThread->status === 'ready'
                                && $this->projectReplyRiskAllowed
                                && (bool) ($this->projectFeedbackContract['project_thread_reply_enabled'] ?? false),
                            'status' => (string) $submission->feedbackThread->status,
                            'remaining_messages' => 0,
                            'messages' => [],
                        ] : null,
                    ] : null;
                    $content['project_feedback'] = [
                        'level' => $this->projectFeedbackLevel,
                        'report_enabled' => (bool) (
                            $this->projectFeedbackContract['project_report_enabled'] ?? false
                        ) && $this->projectFeedbackLevel !== 'pass_only',
                        'reply_enabled' => (bool) (
                            $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                        ) && $this->projectReplyRiskAllowed,
                        'message_limit' => max(0, (int) (
                            $this->projectFeedbackContract['project_message_limit'] ?? 0
                        )),
                        'token_budget' => max(0, (int) (
                            $this->projectFeedbackContract['project_token_budget'] ?? 0
                        )),
                    ];
               break;

            case 'question':
                $content['question'] = $section->sectionable->question ?? null;
                $content['question_image'] = $section->sectionable->question_image ?? null;
                $content['choices'] = [
                    'choice1' => $section->sectionable->choice1 ?? null,
                    'choice2' => $section->sectionable->choice2 ?? null,
                    'choice3' => $section->sectionable->choice3 ?? null,
                    'choice4' => $section->sectionable->choice4 ?? null,
                    'choice5' => $section->sectionable->choice5 ?? null,
                    'choice6' => $section->sectionable->choice6 ?? null
                ];
                $content['priority'] = $section->sectionable->priority ?? null;
                break;

            case 'link':
                $content['title_en'] = $section->sectionable->title_en ?? null;
                $content['description_en'] = $section->sectionable->description_en ?? null;
                $content['link'] = $section->sectionable->link ?? null; // Sensitive data
                $content['type'] = $section->sectionable->type ?? null;
                break;

            case 'quiz':
                $content['type'] = $section->sectionable->type ?? null;
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['time_minutes'] = $section->sectionable->time_minutes ?? null;
                $passedAttempt = $this->passedQuizAttempts->first(
                    fn (ExamAttempt $attempt) => (int) $attempt->section_id === (int) $section->id
                        || ($attempt->section_id === null
                            && (int) $attempt->quiz_id === (int) $section->sectionable->id)
                );
                $content['is_passed'] = $passedAttempt !== null;
                $content['score_percentage'] = $passedAttempt?->score_percentage;
                // Note: Quiz ID is already included in basic 'id' field
                break;

            case 'course':
                $content['title_en'] = $section->sectionable->name_en ?? null;
                $content['description_en'] = $section->sectionable->description_en ?? null;
                $content['image'] = $section->sectionable->image ?? null;
                break;

        }

        return $this->fullSectionContentCache[$sectionId] = $content;
    }
}
