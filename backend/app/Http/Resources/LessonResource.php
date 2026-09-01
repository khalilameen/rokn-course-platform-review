<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\QuizSummaryResource;
use App\Services\BunnyService;

class LessonResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Get video data based on source type
        $videoData = $this->getVideoData();

        return [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'duration_minutes' => max(0, (int) $this->duration_minutes),
            'is_opened' => (bool) $this->is_opened,
            'description' =>  (string)$this->description,
            'video_source_type' => $videoData['video_source_type'],
            'video_link' => $videoData['video_link'],
            'bunny_video_url' => $videoData['bunny_video_url'],
            'bunny_video_expires_at' => $videoData['bunny_video_expires_at'],
            'image' => $this->image ? (string)$this->image: null,
            // Lesson previews may advertise an attached assessment, but the
            // enrolled exam endpoint is the only place that serves questions.
            'quiz' => ($this->quiz_id) ? new QuizSummaryResource($this->quiz) : null,
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];
    }

    /**
     * Get video data including signed URL for Bunny videos
     *
     * @return array
     */
    private function getVideoData(): array
    {
        if (!(bool) $this->resource->is_opened) {
            return [
                'video_source_type' => 'bunny',
                'video_link' => null,
                'bunny_video_url' => null,
                'bunny_video_expires_at' => null,
            ];
        }
        $bunnyService = app(BunnyService::class);
        return $bunnyService->getVideoDataForLesson($this->resource);
    }
}
