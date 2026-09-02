<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\QuizResource;
class ShortLessonResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {// id  list_id title   description video_link  file_link1  file_link2  created_at  updated_at
        $this->resource->loadMissing('mediaState');
        $providerSeconds = max(0, (int) ($this->mediaState?->duration_seconds ?? 0));
        $durationSeconds = $providerSeconds > 0
            ? $providerSeconds
            : max(0, (int) $this->duration_minutes) * 60;

        return [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'duration_minutes' => $durationSeconds > 0
                ? (int) ceil($durationSeconds / 60)
                : max(0, (int) $this->duration_minutes),
            'duration_seconds' => $durationSeconds,
            'is_opened' => (bool) $this->is_opened,
            'description' =>  (string)$this->description,
            'image' => $this->image ? (string)$this->image: null,
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];

    }
}
