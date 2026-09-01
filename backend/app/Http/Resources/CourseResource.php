<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\StudentSectionProgress;
use App\Models\UserProjectEvaluation;
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
use App\Models\CourseEnrollment;

class CourseResource extends BaseCourseResource
{
    private ?BunnyService $bunnyService = null;
    private array $fullSectionContentCache = [];
    private array $sectionLockCache = [];
    private Collection $sectionAccessStates;
    private Collection $orderedSections;
    private Collection $projectEvaluations;
    private Collection $passedQuizAttempts;
    private string $projectFeedbackLevel = 'pass_only';
    private array $projectFeedbackContract = [
        'project_report_enabled' => false,
        'project_thread_reply_enabled' => false,
        'project_message_limit' => 0,
        'project_token_budget' => 0,
    ];
    private bool $learningContextProvided = false;
    private Collection $resolvedCompletedSectionIds;
    private ?array $resolvedEntitlement = null;
    private ?CourseEnrollment $resolvedEnrollment = null;

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
        $user = auth('api')->user();
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
        $this->projectEvaluations = ($user && $projectIds->isNotEmpty())
            ? UserProjectEvaluation::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $projectIds)
                ->get()
                ->keyBy('project_id')
            : collect();
        $quizIds = $sections
            ->filter(fn ($section) => $section->getSectionType() === 'quiz')
            ->pluck('sectionable_id')
            ->filter()
            ->unique()
            ->values();
        $this->passedQuizAttempts = ($user && $quizIds->isNotEmpty())
            ? ExamAttempt::query()
                ->where('user_id', $user->id)
                ->where('course_id', $this->id)
                ->whereIn('quiz_id', $quizIds)
                ->where('status', ExamAttempt::STATUS_COMPLETED)
                ->where('is_passed', true)
                ->get(['id', 'quiz_id', 'section_id', 'score_percentage'])
            : collect();
        $this->sectionAccessStates = app(CoursePresentationService::class)
            ->sectionLockStatus($sections, $completedSectionIds, $user ? (int) $user->id : null)
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
                    $this->projectFeedbackLevel = (string) (
                        $this->projectFeedbackContract['project_feedback_level']
                        ?? 'pass_only'
                    );
                }
            }
        }

        $moduleAccess = app(CourseModuleAccessService::class);
        $hasCourseAccess = $user
            ? ($this->learningContextProvided
                ? (bool) ($this->resolvedEntitlement['has_learning_access'] ?? false)
                : $moduleAccess->hasCourseAccess($user, $this->resource))
            : false;

        $baseData['attachment_prompt'] = [
            'enabled' => $hasCourseAccess && (bool) $this->attachment_prompt_enabled,
            'at_seconds' => max(0, (int) ($this->attachment_prompt_at_seconds ?? 20)),
            'title' => trim((string) $this->attachment_prompt_title) ?: 'مرفقات تساعدك في التطبيق',
            'body' => trim((string) $this->attachment_prompt_body)
                ?: 'هذه الوحدة تتضمن ملفات تساعدك على التطبيق',
            'button_text' => trim((string) $this->attachment_prompt_button_text) ?: 'عرض المرفقات',
            'frequency' => 'once_per_course',
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
                ->for($user, $this->resource, $hasCourseAccess);
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

        // Override sections with full content and lock status
        $baseData['sections'] = $this->whenLoaded('sections', function() {
            return $this->orderedSections->map(function($section) {
                $isLocked = $this->isSectionLockedFromState($section);
                $isPreview = $section->getSectionType() === 'lesson'
                    && (bool) ($section->sectionable?->is_opened ?? false);
                $sectionData = [
                    'id' => $section->id,
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
                        + $coursePdfs->count(),
                    'sections' => $moduleSections->map(function($section) use ($hasCourseAccess, $moduleAccess, $user, $module) {
                        $isLocked = $this->isSectionLockedFromState($section);
                        $isPreview = $section->getSectionType() === 'lesson'
                            && (bool) ($section->sectionable?->is_opened ?? false);
                        $sectionData = [
                            'id' => $section->id,
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
                    $moduleData['attachments_link'] = $module->attachments_link;
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
        return StudentSectionProgress::where('user_id', $user->id)
            ->whereIn('course_section_id', $courseSectionIds)
            ->where('is_completed', true)
            ->pluck('course_section_id');
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
                $fallbackVideo = $isPreview && $section->sectionable->bunny_video_id
                    ? $bunnyService->getFallbackVideo((string) $section->sectionable->bunny_video_id)
                    : null;

                $content['video_source_type'] = $videoData['video_source_type'];
                $content['video_link'] = $videoData['video_link'];
                $content['bunny_video_url'] = $videoData['bunny_video_url'];
                $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
                $content['fallback_video_url'] = $fallbackVideo['url'] ?? null;
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['duration_minutes'] = (int)($section->sectionable->duration_minutes ?? 0);
                $content['duration_seconds'] = $this->lessonDurationSeconds($section->sectionable) ?: null;
                $content['thumbnail_url'] = $section->sectionable->thumbnail_path
                    ? $bunnyService->generateBunnySignedUrl($section->sectionable->thumbnail_path)
                    : null;
                break;

                case 'project':
                    $content['requirements_text'] = $section->sectionable->requirements_text ?? null;
                    $content['passing_score'] = $section->sectionable->passing_score ?? null;
                    $content['is_graduation_project'] = $section->sectionable->is_graduation_project ?? false;
                    $evaluation = $this->projectEvaluations->get((int) $section->sectionable->id);
                    $content['status'] = data_get($evaluation?->evaluation_data, 'status')
                        ?: ($evaluation ? ($evaluation->passed ? 'passed' : 'pending') : 'not_submitted');
                    $content['passed'] = (bool) ($evaluation?->passed ?? false);
                    $content['project_feedback'] = [
                        'level' => $this->projectFeedbackLevel,
                        'report_enabled' => (bool) (
                            $this->projectFeedbackContract['project_report_enabled'] ?? false
                        ),
                        'reply_enabled' => (bool) (
                            $this->projectFeedbackContract['project_thread_reply_enabled'] ?? false
                        ),
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
