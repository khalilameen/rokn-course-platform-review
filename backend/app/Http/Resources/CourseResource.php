<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\StudentSectionProgress;
use App\Models\CourseEnrollment;
use App\Models\Setting;
use App\Models\Project;
use App\Models\UserProjectEvaluation;
use App\Models\CourseModule;
use App\Services\BunnyService;
use App\Models\CourseRating;
use Illuminate\Support\Collection;
use App\Services\CourseModuleAccessService;

class CourseResource extends BaseCourseResource
{
    private ?BunnyService $bunnyService = null;
    private array $fullSectionContentCache = [];
    private array $sectionLockCache = [];
    private array $previousSectionsById = [];
    private Collection $projectEvaluations;

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
        $completedSectionIds = $this->getCompletedSectionIds($user, $sections);
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
        $passedProjectIds = $this->projectEvaluations
            ->where('passed', true)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();
        $enforceSectionOrder = (bool) (Setting::query()->value('enforce_course_section_order') ?? true);

        $previousSection = null;
        foreach ($sections->sortBy('order') as $section) {
            $this->previousSectionsById[(int) $section->id] = $previousSection;
            $previousSection = $section;
        }

        // Check if user is enrolled in this course
        $enrollment = null;
        if ($user) {
            $candidate = CourseEnrollment::where('user_id', $user->id)
                ->where('course_id', $this->id)
                ->where('is_active', true)
                ->first();
            $enrollment = $candidate && $candidate->isActive() ? $candidate : null;
        }

        $moduleAccess = app(CourseModuleAccessService::class);
        $hasCourseAccess = $user ? $moduleAccess->hasCourseAccess($user, $this->resource) : false;

        // Add enrollment information
        $baseData['enrollment'] = $enrollment ? [
            'id' => $enrollment->id,
            'enrolled_at' => $enrollment->enrolled_at,
            'expires_at' => $enrollment->expires_at,
            'is_active' => $enrollment->isActive(),
            'access_granted_at' => $enrollment->access_granted_at
        ] : null;

        // Add user rating if exists
        $userRating = null;
        if ($user) {
            $userRating = CourseRating::where('user_id', $user->id)
                ->where('course_id', $this->id)
                ->first();
        }

        $baseData['user_rating'] = $userRating ? [
            'id' => $userRating->id,
            'rating' => (int)$userRating->rating,
            'comment' => $userRating->comment,
            'created_at' => $userRating->created_at,
        ] : null;

        // Override sections with full content and lock status
        $baseData['sections'] = $this->whenLoaded('sections', function() use ($completedSectionIds, $passedProjectIds, $enforceSectionOrder) {
            return $this->sections->map(function($section) use ($completedSectionIds, $passedProjectIds, $enforceSectionOrder) {
                $isLocked = $this->isSectionLockedOptimized(
                    $section,
                    $completedSectionIds,
                    $passedProjectIds,
                    $enforceSectionOrder
                );
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
        $baseData['modules'] = $this->whenLoaded('modules', function() use ($completedSectionIds, $passedProjectIds, $enforceSectionOrder, $hasCourseAccess, $moduleAccess, $user) {
            return $this->modules->map(function($module) use ($completedSectionIds, $passedProjectIds, $enforceSectionOrder, $hasCourseAccess, $moduleAccess, $user) {
                $moduleSections = $module->sections
                    ? $module->sections->sortBy('order')->values()
                    : collect();
                $firstSection = $moduleSections->first();
                $moduleIsLocked = !$firstSection || (
                    $this->isSectionLockedOptimized(
                        $firstSection,
                        $completedSectionIds,
                        $passedProjectIds,
                        $enforceSectionOrder
                    )
                    && !(
                        $firstSection->getSectionType() === 'lesson'
                        && (bool) ($firstSection->sectionable?->is_opened ?? false)
                    )
                );

                $moduleData = [
                    'id' => $module->id,
                    'title' => $module->title,
                    'description' => $module->description,
                    'attachment_platform' => $module->attachment_platform,
                    'order' => $module->order,
                    'is_locked' => $moduleIsLocked,
                    'attachments_count' => $module->attachments->count(),
                    'sections' => $moduleSections->map(function($section) use ($completedSectionIds, $passedProjectIds, $enforceSectionOrder) {
                        $isLocked = $this->isSectionLockedOptimized(
                            $section,
                            $completedSectionIds,
                            $passedProjectIds,
                            $enforceSectionOrder
                        );
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

                        return $sectionData;
                    }),
                ];

                if ($hasCourseAccess && !$moduleIsLocked && $user) {
                    $moduleData['attachments_link'] = $module->attachments_link;
                    $moduleData['attachments'] = $module->attachments->map(function($attachment) use ($moduleAccess, $user, $module) {
                        return [
                            'id' => $attachment->id,
                            'title' => $attachment->title,
                            'download_url' => $moduleAccess->temporaryDownloadUrl($user, $this->resource, $module, $attachment),
                            'expires_in_seconds' => max(60, min(1800, (int) config('course_attachments.signed_url_minutes', 30) * 60)),
                            'download_url_is_temporary' => true,
                            'download_url_hint' => 'الرابط صالح لمدة ٣٠ دقيقة ويمكنك إنشاء رابط جديد من صفحة الكورس.',
                            'file_type' => $attachment->file_type,
                            'file_size' => $attachment->file_size_human,
                        ];
                    });
                }

                return $moduleData;
            });
        });

        return $baseData;
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

    /**
     * Optimized section locking check using pre-loaded completed sections and passed projects
     * A section is locked if the previous section is not completed (only if enforce_course_section_order is enabled)
     * OR if it's in a new module and the previous module's project was not passed
     *
     * @param \App\Models\CourseSection $section
     * @param \Illuminate\Support\Collection $completedSectionIds
     * @param array $passedProjectIds
     * @return bool
     */
    protected function isSectionLockedOptimized(
        $section,
        $completedSectionIds,
        $passedProjectIds = [],
        bool $enforceSectionOrder = true
    )
    {
        $sectionId = (int) $section->id;
        if (array_key_exists($sectionId, $this->sectionLockCache)) {
            return $this->sectionLockCache[$sectionId];
        }

        // If section order is not enforced, no sections are locked
        if (!$enforceSectionOrder) {
            return $this->sectionLockCache[$sectionId] = false;
        }

        // First section is never locked
        if ($section->order == 1) {
            return $this->sectionLockCache[$sectionId] = false;
        }

        // Get the previous section (highest order less than current section)
        $previousSection = $this->previousSectionsById[$sectionId] ?? null;

        if (!$previousSection) {
            return $this->sectionLockCache[$sectionId] = false;
        }

        // Check if previous section is completed using pre-loaded data
        if (!$completedSectionIds->contains($previousSection->id)) {
            return $this->sectionLockCache[$sectionId] = true;
        }

        // Check module-gated progression
        if ($section->module_id && $previousSection->module_id !== $section->module_id) {
            // New module - check previous module's project

            // Get previous module
            $previousModuleId = $previousSection->module_id;

            // If previous section had a module, check if that module has a project
            if ($previousModuleId) {
                // Find project section in previous module (from this->sections loaded)
                $previousModuleProjectSection = $this->sections->first(function($s) use ($previousModuleId) {
                    return $s->module_id == $previousModuleId && $s->getSectionType() === 'project';
                });

                if ($previousModuleProjectSection) {
                    // Check if project is passed
                    // Since we can't easily access project ID from section here without loading project relation on all sections,
                    // we assume we loaded it or find it.
                    // Actually, sectionable_type='Project' is not true, project is via section_type='project'.
                    // Section has one Project model.
                    // To avoid N+1, we rely on the fact we fetched passedProjectIds.
                    // We need the project ID.
                    $projectId = $previousModuleProjectSection->sectionable_type === Project::class
                        ? (int) $previousModuleProjectSection->sectionable_id
                        : null;

                    if ($projectId && !in_array($projectId, $passedProjectIds, true)) {
                        return $this->sectionLockCache[$sectionId] = true;
                    }
                }
            }
        }

        return $this->sectionLockCache[$sectionId] = false;
    }

    /**
     * Legacy method - kept for backward compatibility
     * Check if a section is locked for the user
     * A section is locked if the previous section is not completed (only if enforce_course_section_order is enabled)
     *
     * @param \App\Models\CourseSection $section
     * @param \App\Models\User|null $user
     * @return bool
     */
    protected function isSectionLocked($section, $user)
    {
        if (!$user) {
            return true; // Not logged in = all sections locked
        }

        // Get settings to check if section order enforcement is enabled
        $settings = Setting::first();
        $enforceSectionOrder = $settings ? $settings->enforce_course_section_order : true;

        // If section order is not enforced, no sections are locked
        if (!$enforceSectionOrder) {
            return false;
        }

        // First section is never locked
        if ($section->order == 1) {
            return false;
        }

        // Get the previous section
        $previousSection = $this->sections
            ->where('order', '<', $section->order)
            ->sortByDesc('order')
            ->first();

        if (!$previousSection) {
            return false; // No previous section = not locked
        }

        // Check if previous section is completed
        $isCompleted = StudentSectionProgress::where('user_id', $user->id)
            ->where('course_section_id', $previousSection->id)
            ->where('is_completed', true)
            ->exists();

        return !$isCompleted; // Locked if previous section is not completed
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
                // Get video data with signed URL for Bunny videos
                $bunnyService = $this->bunnyService ??= app(BunnyService::class);
                $videoData = $bunnyService->getVideoDataForLesson($section->sectionable);

                $content['video_source_type'] = $videoData['video_source_type'];
                $content['video_link'] = $videoData['video_link'];
                $content['bunny_video_url'] = $videoData['bunny_video_url'];
                $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['duration_minutes'] = (int)($section->sectionable->duration_minutes ?? 0);
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
                // Note: Quiz ID is already included in basic 'id' field
                break;

            case 'course':
                $content['title_en'] = $section->sectionable->name_en ?? null;
                $content['description_en'] = $section->sectionable->description_en ?? null;
                $content['image'] = $section->sectionable->image ?? null;
                break;

            case 'pdf':
                if ($section->sectionable) {
                    $content['pdf_id'] = $section->sectionable->id;
                    $content['title'] = $section->sectionable->title ?? null;
                    $content['title_en'] = $section->sectionable->title_en ?? null;
                    $content['description'] = $section->sectionable->description ?? null;
                    $content['description_en'] = $section->sectionable->description_en ?? null;
                    $content['file_size'] = $section->sectionable->formatted_file_size ?? null;
                    $content['order'] = $section->sectionable->order ?? null;
                    // Secure stream URL - no direct file access
                    $content['stream_url'] = url("/api/v1/courses/{$section->course_id}/pdfs/{$section->sectionable->id}/stream");
                }
                break;
        }

        return $this->fullSectionContentCache[$sectionId] = $content;
    }
}
