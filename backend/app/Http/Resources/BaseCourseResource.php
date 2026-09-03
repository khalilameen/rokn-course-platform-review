<?php

namespace App\Http\Resources;

use App\Support\PublicDiskUrl;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\RoknLocale;
use App\Services\BunnyService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseDurationService;
use App\Services\CourseSectionSequenceService;
use App\Services\SafeExternalUrl;
use App\Support\RoknPublicUrl;

class BaseCourseResource extends JsonResource
{
    private ?string $entitlementAccessType = null;
    private ?bool $entitlementChatAvailable = null;
    private ?bool $entitlementCertificateIncluded = null;
    private ?bool $entitlementCertificateAvailable = null;

    /**
     * Attach request-specific access without mutating or serialising it on the
     * Course model. Catalogue resources therefore keep describing the course,
     * while the details response describes the current learner's entitlement.
     */
    public function withEntitlement(
        string $accessType,
        bool $chatAvailable,
        bool $certificateIncluded = false,
        bool $certificateAvailable = false
    ): static
    {
        $this->entitlementAccessType = $accessType;
        $this->entitlementChatAvailable = $chatAvailable;
        $this->entitlementCertificateIncluded = $certificateIncluded;
        $this->entitlementCertificateAvailable = $certificateAvailable;

        return $this;
    }

    /**
     * Transform the resource into an array.
     * Base course resource with general data (excluding sensitive links/URLs)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $attributes = $this->resource->getAttributes();
        $activePlans = $this->relationLoaded('accessPlans')
            ? $this->accessPlans->where('is_active', true)
            : collect();
        $hasActivePlans = array_key_exists('catalog_has_active_plans', $attributes)
            ? (bool) $attributes['catalog_has_active_plans']
            : $activePlans->isNotEmpty();
        $price = $hasActivePlans
            ? (array_key_exists('catalog_min_price_coins', $attributes)
                ? (float) $attributes['catalog_min_price_coins']
                : (float) $activePlans->min('price_coins'))
            : null;
        $catalogueChatAvailable = array_key_exists('catalog_chat_available', $attributes)
            ? (bool) $attributes['catalog_chat_available']
            : $activePlans->contains(fn ($plan) => (bool) $plan->chat_enabled);
        $ratingsCount = array_key_exists('ratings_count', $attributes)
            ? (int) $attributes['ratings_count']
            : 0;
        $ratingsAverage = array_key_exists('ratings_avg_rating', $attributes)
            ? (float) $attributes['ratings_avg_rating']
            : null;
        $previewReelsCount = array_key_exists('preview_reels_count', $attributes)
            ? (int) $attributes['preview_reels_count']
            : ($this->relationLoaded('sections')
                ? $this->sections->filter(fn ($section) =>
                    $section->getSectionType() === 'lesson'
                    && $section->relationLoaded('sectionable')
                    && (bool) ($section->sectionable?->is_opened ?? false)
                )->count()
                : 0);
        $activeStudentsCount = array_key_exists('active_enrollments_count', $attributes)
            ? max(0, (int) $attributes['active_enrollments_count'])
            : null;
        // Public social proof is real-time enrollment data. The legacy manual
        // counter stays out of the public contract and financial reporting.
        $displayStudentsCount = $activeStudentsCount;
        $durationMinutes = array_key_exists('duration_minutes_computed', $attributes)
            ? max(0, (int) $attributes['duration_minutes_computed'])
            : app(CourseDurationService::class)->minutes($this->resource);
        $videoCount = $this->relationLoaded('sections')
            ? $this->sections->where('sectionable_type', \App\Models\Lesson::class)->count()
            : (array_key_exists('video_reels_count', $attributes)
                ? max(0, (int) $attributes['video_reels_count'])
                : max(0, (int) ($this->video_count ?? 0)));
        $coursePublished = $this->resource->isPublishedForLearning();
        $courseShareable = $coursePublished
            && (bool) $this->is_catalog_visible
            && !$this->resource->isNestedCourse();

        return [
            'id' => (int)$this->id,
            // A loaded course map names one immutable published graph. Mobile
            // uses this token to replace a stale back-stack map after publish.
            'published_revision' => max(0, (int) (
                $this->last_published_authoring_version ?: $this->authoring_version
            )),
            'share_url' => $courseShareable
                ? RoknPublicUrl::course((int) $this->id)
                : null,
            'access_type' => $this->when(
                $this->entitlementAccessType !== null,
                $this->entitlementAccessType
            ),
            'chat_available' => $this->when(
                $this->entitlementChatAvailable !== null,
                $this->entitlementChatAvailable
            ),
            'certificate_available' => $this->when(
                $this->entitlementCertificateAvailable !== null,
                $this->entitlementCertificateAvailable
            ),
            'certificate_included' => $this->when(
                $this->entitlementCertificateIncluded !== null,
                $this->entitlementCertificateIncluded
            ),
            'title' => (string) $this->title,
            'description' => $this->description ,
            'image' => $this->image ? (string)$this->image : null,
            'price' => $price,
            // Course prices are virtual Rokn credits, never a cash or crypto amount.
            'currency' => 'rokn_coins',
            'currency_type' => 'rokn_coins',
            'currency_label' => 'عملة ركن',
            'is_free' => $hasActivePlans && $price !== null && $price <= 0,
            // Plan prices belong only on course details. Keeping them out of
            // catalogue rows avoids N+1 queries and preserves the clean home.
            'access_plans' => $this->when(
                $request->route('courseId') !== null && $coursePublished,
                function () use ($activePlans) {
                    $plans = app(CourseAccessPlanService::class);

                    return ($this->relationLoaded('accessPlans')
                        ? $activePlans->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                        : $plans->publicPlans($this->resource))
                        ->map(fn ($plan) => $plans->publicPayload($plan))
                        ->values();
                }
            ),
            'is_main_course' => (bool)$this->is_main_course,
            'is_coming_soon' => (bool)$this->is_coming_soon,
            'home_sort_order' => (int) ($this->home_sort_order ?? 100),
            'catalog_badge' => [
                'label' => (string) (RoknLocale::isArabic()
                    ? ($this->catalog_badge_ar ?: $this->catalog_badge_en)
                    : ($this->catalog_badge_en ?: $this->catalog_badge_ar)),
                'tone' => in_array($this->catalog_badge_tone, ['blue', 'green', 'gold', 'neutral'], true)
                    ? $this->catalog_badge_tone
                    : 'blue',
            ],
            'average_rating' => $ratingsCount > 0 ? $ratingsAverage : null,
            'ratings_count' => $ratingsCount,
            'path_id' => $this->path_id,
            'path_title' => $this->coursePath ? $this->coursePath->title : null,
            'ratings' => CourseRatingResource::collection($this->whenLoaded('ratings')),
            'tags' => $this->classifications->map(function($classification) {
                return [
                    'id' => $classification->id,
                    'name_ar' => $classification->name_ar,
                    'name_en' => $classification->name_en,
                    'show_on_home' => (bool) $classification->show_on_home,
                    'home_order' => (int) ($classification->home_order ?? 100),
                ];
            }),
            'teachers' => $this->publicTeachers()->map(function($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'job_title' => $teacher->job_title,
                    'bio' => $teacher->bio,
                    'image' => $teacher->photo
                        ? PublicDiskUrl::from($teacher->photo->path)
                        : ($teacher->profile_image_url ?: null),
                ];
            }),

            // The public map never contains paid reel content. Explicit
            // previews keep their playable data for the try-before-unlock flow.
            'sections' => $this->whenLoaded('sections', function () use ($coursePublished) {
                return app(CourseSectionSequenceService::class)
                    ->ordered($this->sections)
                    ->map(function ($section) use ($coursePublished) {
                    $isPreview = $coursePublished
                        && $section->getSectionType() === 'lesson'
                        && $section->relationLoaded('sectionable')
                        && (bool) ($section->sectionable?->is_opened ?? false);
                    $data = [
                        'id' => $section->id,
                        'content_id' => $section->sectionable_id,
                        'title' => $section->title,
                        'type' => $section->getSectionType(),
                        'order' => $section->order,
                        'module_id' => $section->module_id,
                        'is_preview' => $isPreview,
                        'is_locked' => !$isPreview,
                        'lock_reason' => $isPreview ? null : 'course_purchase_required',
                    ];
                    if ($isPreview) {
                        $data['content'] = $this->getBasicSectionContent($section);
                    }

                    return $data;
                });
            }),

            // Modules information
            'modules' => $this->whenLoaded('modules', function() use ($coursePublished) {
                return $this->modules->sortBy([
                    ['order', 'asc'],
                    ['id', 'asc'],
                ])->values()->map(function($module) use ($coursePublished) {
                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'attachment_platform' => $module->attachment_platform,
                        'order' => $module->order,
                        // Buyers receive attachment links from CourseResource.
                        // Before purchase only the map and counts are public.
                        'attachments_count' => $module->attachments->count()
                            + $module->sections->sum(fn ($section) => $section->attachments->count())
                            + (SafeExternalUrl::sanitize($module->attachments_link) !== null ? 1 : 0),
                        'sections' => $module->sections->sortBy([
                            ['order', 'asc'],
                            ['id', 'asc'],
                        ])->values()->map(function($section) use ($coursePublished) {
                        $isPreview = $coursePublished
                            && $section->getSectionType() === 'lesson'
                            && $section->relationLoaded('sectionable')
                            && (bool) ($section->sectionable?->is_opened ?? false);
                        $data = [
                            'id' => $section->id,
                            'content_id' => $section->sectionable_id,
                            'title' => $section->title,
                            'type' => $section->getSectionType(),
                            'order' => $section->order,
                            'is_preview' => $isPreview,
                            'is_locked' => !$isPreview,
                            'lock_reason' => $isPreview ? null : 'course_purchase_required',
                        ];
                        if ($isPreview) {
                            $data['content'] = $this->getBasicSectionContent($section);
                        }

                        return $data;
                        })
                    ];
                });
            }),

            // Metadata
            'metadata' => [
                'video_count' => $this->when($videoCount > 0, $videoCount),
                'duration_minutes' => $this->when($durationMinutes > 0, $durationMinutes),
                'home_work_count' => $this->when((int) ($this->home_work_count ?? 0) > 0, (int) $this->home_work_count),
                'files_count' => $this->when((int) ($this->files_count ?? 0) > 0, (int) $this->files_count),
                'students_count' => $this->when($displayStudentsCount !== null, $displayStudentsCount),
                'sections_count' => $this->when((int) ($this->sections_count ?? 0) > 0, (int) $this->sections_count),
                'preview_reels_count' => $previewReelsCount,
                'chat_available' => $this->entitlementChatAvailable
                    ?? $catalogueChatAvailable,
            ],

            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at,
        ];
    }

    /**
     * Get basic section content without sensitive data
     *
     * @param \App\Models\CourseSection $section
     * @return array
     */
    protected function getBasicSectionContent($section)
    {
        if (!$section->sectionable) {
            return null;
        }

        $content = [
            'id' => $section->sectionable->id,
            'title' => $section->sectionable->title ?? $section->sectionable->name_ar ?? null,
            'description' => $section->sectionable->description ?? $section->sectionable->description_ar ?? null,
        ];

        // Add type-specific basic data
        switch ($section->getSectionType()) {
            case 'lesson':
                $durationSeconds = $this->lessonDurationSeconds($section->sectionable);
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['duration_minutes'] = $durationSeconds > 0
                    ? (int) ceil($durationSeconds / 60)
                    : max(0, (int) ($section->sectionable->duration_minutes ?? 0));
                $content['duration_seconds'] = $durationSeconds ?: null;
                $bunnyService = app(BunnyService::class);
                $content['thumbnail_url'] = $section->sectionable->thumbnail_path
                    ? $bunnyService->generateBunnySignedUrl($section->sectionable->thumbnail_path)
                    : null;
                if($section->sectionable->is_opened ){
                    // Get video data with signed URL for Bunny videos
                    $videoData = $bunnyService->getVideoDataForLesson($section->sectionable);
                    $fallbackVideo = !empty($videoData['bunny_video_url'])
                        && $section->sectionable->bunny_video_id
                        ? $bunnyService->getFallbackVideo((string) $section->sectionable->bunny_video_id)
                        : null;

                    $content['video_source_type'] = $videoData['video_source_type'];
                    $content['video_link'] = $videoData['video_link'];
                    $content['bunny_video_url'] = $videoData['bunny_video_url'];
                    $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
                    $content['fallback_video_url'] = $fallbackVideo['url'] ?? null;
                    $content['priority'] = $section->sectionable->priority ?? null;
                }

                break;

            case 'question':
                // The public map advertises the assessment title only. The
                // question body is served through the enrolled assessment API.
                $content['priority'] = $section->sectionable->priority ?? null;
                break;

            case 'link':
                $content['title_en'] = $section->sectionable->title_en ?? null;
                $content['description_en'] = $section->sectionable->description_en ?? null;
                $content['type'] = $section->sectionable->type ?? null;
                // Note: 'link' is excluded (sensitive data)
                break;

            case 'quiz':
                $content['type'] = $section->sectionable->type ?? null;
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['time_minutes'] = $section->sectionable->time_minutes ?? null;
                // Note: 'id' is excluded (sensitive data for quiz)
                break;

            case 'course':
                $content['title_en'] = $section->sectionable->name_en ?? null;
                $content['description_en'] = $section->sectionable->description_en ?? null;
                $content['image'] = $section->sectionable->image ?? null;
                break;
        }

        return $content;
    }

    protected function lessonDurationSeconds(\App\Models\Lesson $lesson): int
    {
        return max(0, (int) (
            $lesson->relationLoaded('mediaState')
                ? $lesson->mediaState?->duration_seconds
                : $lesson->mediaState()->value('duration_seconds')
        ));
    }

    private function publicTeachers(): \Illuminate\Support\Collection
    {
        $teachers = $this->relationLoaded('teachers')
            ? $this->teachers->filter(fn ($teacher) => (bool) $teacher->active)
            : collect();
        if ($teachers->isNotEmpty()) {
            return $teachers->values();
        }

        $teacher = $this->relationLoaded('teacher') ? $this->teacher : null;

        return $teacher && (bool) $teacher->active
            ? collect([$teacher])
            : collect();
    }
}

