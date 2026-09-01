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
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'slug' => $this->slug,
            'role' => $this->role,
            'tools' => $this->tools ?? [],
            'external_url' => SafeExternalUrl::sanitize($this->external_url),
            'completed_at' => $this->completed_at?->format('Y-m-d'),
            // Compatibility field for older clients. Portfolio entries live on
            // an unlisted share page and no longer have per-item publication.
            'is_public' => true,
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
