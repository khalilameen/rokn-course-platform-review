<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SavedLessonResource extends JsonResource
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
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'duration_minutes' => (int)$this->duration_minutes,
            'description' => (string)$this->description,
            'is_opened' => (bool)$this->is_opened,
            'image' => $this->thumbnail_path ? (string)$this->thumbnail_path : null,
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at,
            'saved_at' => $this->when(
                array_key_exists('saved_at', $this->getAttributes()),
                fn () => (string) $this->getAttribute('saved_at')
            ),
            'folder_memberships' => $this->whenLoaded('savedFolders', function () {
                return $this->savedFolders->map(static fn ($folder) => [
                    'id' => (int) $folder->id,
                    'name' => (string) $folder->name,
                ])->values();
            }),
            'course' => $this->course ? [
                'id' => (int)$this->course->id,
                'title' => (string)$this->course->title,
                'image' => $this->course->image ? (string)$this->course->image : null,
            ] : null,
        ];
    }
}
