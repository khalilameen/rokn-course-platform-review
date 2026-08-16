<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuizSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'type' => (string) $this->type,
            'description' => $this->description ? (string) $this->description : null,
            'image' => $this->image ? (string) $this->image : null,
            'time_minutes' => $this->time_minutes ? (int) $this->time_minutes : null,
            'questions_count' => $this->questions()->count(),
        ];
    }
}
