<?php

namespace App\Http\Resources;

use App\Services\SafeExternalUrl;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $uploadedMediaCount = isset($this->media_files_count)
            ? (int) $this->media_files_count
            : ($this->relationLoaded('mediaFiles') ? $this->mediaFiles->count() : 0);
        $expectedMediaCount = max(0, (int) $this->expected_media_count);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'slug' => $this->slug,
            'role' => $this->role,
            'tools' => $this->tools ?? [],
            'external_url' => SafeExternalUrl::sanitize($this->external_url),
            'completed_at' => $this->completed_at?->format('Y-m-d'),
            'is_public' => (bool) $this->is_public,
            'upload_state' => $this->deletion_started_at
                ? 'deleting'
                : ((bool) $this->is_public
                    ? 'ready'
                    : ($uploadedMediaCount > 0 ? 'uploading' : 'draft')),
            'uploaded_media_count' => $uploadedMediaCount,
            'expected_media_count' => $expectedMediaCount,
            'is_featured' => (bool) $this->is_featured,
            'sort_order' => (int) $this->sort_order,
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id,
                'name' => $this->course->name_ar ?: $this->course->name_en,
                'image' => $this->course->image ? (string) $this->course->image : null,
            ] : null),
            'source_project_id' => $this->source_project_id,
            'media' => PortfolioMediaResource::collection($this->whenLoaded('mediaFiles')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
