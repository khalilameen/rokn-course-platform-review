<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SavedFolderResource extends JsonResource
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
            'name' => (string)$this->name,
            'image' => $this->resolveFolderImage(),
            'lessons_count' => $this->lessons_count ?? (int)$this->lessons()->count(),
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at,
        ];
    }

    private function resolveFolderImage(): string
    {
        $firstLesson = $this->relationLoaded('lessons')
            ? $this->lessons->first()
            : $this->lessons()->with('course')->orderByPivot('created_at')->first();

        if ($firstLesson) {
            if (!empty($firstLesson->thumbnail_path)) {
                return (string)$firstLesson->thumbnail_path;
            }

            if ($firstLesson->relationLoaded('course') && $firstLesson->course && $firstLesson->course->image) {
                return (string)$firstLesson->course->image;
            }

            if (!$firstLesson->relationLoaded('course')) {
                $firstLesson->load('course');
            }

            if ($firstLesson->course && $firstLesson->course->image) {
                return (string)$firstLesson->course->image;
            }
        }

        return asset('images/default-folder.png');
    }
}
