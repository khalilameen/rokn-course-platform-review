<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class QuizSummaryResource extends JsonResource
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
            'questions_count' => (int) ($this->questions_count ?? 0),
            'created_at' => (string) $this->created_at,
            'updated_at' => (string) $this->updated_at,
        ];
    }
}
