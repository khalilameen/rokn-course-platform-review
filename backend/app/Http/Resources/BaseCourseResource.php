<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\BunnyService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseDurationService;

class BaseCourseResource extends JsonResource
{
    private ?string $entitlementAccessType = null;
    private ?bool $entitlementChatAvailable = null;
    private ?bool $entitlementCertificateAvailable = null;

    /**
     * Attach request-specific access without mutating or serialising it on the
     * Course model. Catalogue resources therefore keep describing the course,
     * while the details response describes the current learner's entitlement.
     */
    public function withEntitlement(
        string $accessType,
        bool $chatAvailable,
        bool $certificateAvailable = false
    ): static
    {
        $this->entitlementAccessType = $accessType;
        $this->entitlementChatAvailable = $chatAvailable;
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
        $price = $this->price !== null ? (float) $this->price : null;
        $priceBeforeDiscount = $this->price_before_discount !== null
            ? (float) $this->price_before_discount
            : null;
        $attributes = $this->resource->getAttributes();
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

        return [
            'id' => (int)$this->id,
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
            'title' => (string) $this->title,
            'description' => $this->description ,
            'image' => $this->image ? (string)$this->image : null,
            'price' => $price,
            'price_before_discount' => $this->when(
                $priceBeforeDiscount !== null && $priceBeforeDiscount > (float) ($price ?? 0),
                $priceBeforeDiscount
            ),
            // Course prices are virtual Rokn credits, never a cash or crypto amount.
            'currency' => 'rokn_coins',
            'currency_type' => 'rokn_coins',
            'currency_label' => 'عملة ركن',
            'is_free' => $price !== null && $price <= 0,
            // Plan prices belong only on course details. Keeping them out of
            // catalogue rows avoids N+1 queries and preserves the clean home.
            'access_plans' => $this->when(
                $request->route('courseId') !== null,
                fn () => app(CourseAccessPlanService::class)
                    ->publicPlans($this->resource)
                    ->map(fn ($plan) => app(CourseAccessPlanService::class)->publicPayload($plan))
                    ->values()
            ),
            'is_main_course' => (bool)$this->is_main_course,
            'is_coming_soon' => (bool)$this->is_coming_soon,
            'home_sort_order' => (int) ($this->home_sort_order ?? 100),
            'catalog_badge' => [
                'label' => (string) (str_starts_with((string) $request->header('Accept-Language', 'ar'), 'en')
                    ? ($this->catalog_badge_en ?: $this->catalog_badge_ar)
                    : ($this->catalog_badge_ar ?: $this->catalog_badge_en)),
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
            'teachers' => $this->teachers->map(function($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'job_title' => $teacher->job_title,
                    'bio' => $teacher->bio,
                    'image' => $teacher->profile_image_url ?: null,
                ];
            }),

            // The public map never contains paid reel content. Explicit
            // previews keep their playable data for the try-before-unlock flow.
            'sections' => $this->whenLoaded('sections', function () {
                return $this->sections->map(function ($section) {
                    $isPreview = $section->getSectionType() === 'lesson'
                        && $section->relationLoaded('sectionable')
                        && (bool) ($section->sectionable?->is_opened ?? false);
                    $data = [
                        'id' => $section->id,
                        'title' => $section->title,
                        'type' => $section->getSectionType(),
                        'order' => $section->order,
                        'module_id' => $section->module_id,
                        'is_preview' => $isPreview,
                    ];
                    if ($isPreview) {
                        $data['content'] = $this->getBasicSectionContent($section);
                    }

                    return $data;
                });
            }),

            // Modules information
            'modules' => $this->whenLoaded('modules', function() {
                return $this->modules->map(function($module) {
                    return [
                        'id' => $module->id,
                        'title' => $module->title,
                        'attachment_platform' => $module->attachment_platform,
                        'order' => $module->order,
                        // Buyers receive attachment links from CourseResource.
                        // Before purchase only the map and counts are public.
                        'attachments_count' => $module->attachments->count(),
                        'sections' => $module->sections->map(function($section) {
                        $isPreview = $section->getSectionType() === 'lesson'
                            && $section->relationLoaded('sectionable')
                            && (bool) ($section->sectionable?->is_opened ?? false);
                        $data = [
                            'id' => $section->id,
                            'title' => $section->title,
                            'type' => $section->getSectionType(),
                            'order' => $section->order,
                            'is_preview' => $isPreview,
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
                'video_count' => $this->when((int) ($this->video_count ?? 0) > 0, (int) $this->video_count),
                'hours_count' => $this->when((int) ($this->hours_count ?? 0) > 0, (int) $this->hours_count),
                'duration_minutes' => $this->when($durationMinutes > 0, $durationMinutes),
                'home_work_count' => $this->when((int) ($this->home_work_count ?? 0) > 0, (int) $this->home_work_count),
                'files_count' => $this->when((int) ($this->files_count ?? 0) > 0, (int) $this->files_count),
                'students_count' => $this->when($displayStudentsCount !== null, $displayStudentsCount),
                'sections_count' => $this->when((int) ($this->sections_count ?? 0) > 0, (int) $this->sections_count),
                'preview_reels_count' => $previewReelsCount,
                'chat_available' => $this->entitlementChatAvailable
                    ?? ((bool) $this->ai_chat_enabled
                        && (!empty($this->ai_model_type) || !empty(config('openrouter.default_model')))),
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
                $content['priority'] = $section->sectionable->priority ?? null;
                $content['is_opened'] = $section->sectionable->is_opened ?? true;
                $content['duration_minutes'] = (int)($section->sectionable->duration_minutes ?? 0);
                $bunnyService = new BunnyService();
                $content['thumbnail_url'] = $section->sectionable->thumbnail_path
                    ? $bunnyService->generateBunnySignedUrl($section->sectionable->thumbnail_path)
                    : null;
                if($section->sectionable->is_opened ){
                    // Get video data with signed URL for Bunny videos
                    $videoData = $bunnyService->getVideoDataForLesson($section->sectionable);

                    $content['video_source_type'] = $videoData['video_source_type'];
                    $content['video_link'] = $videoData['video_link'];
                    $content['bunny_video_url'] = $videoData['bunny_video_url'];
                    $content['bunny_video_expires_at'] = $videoData['bunny_video_expires_at'];
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
}

