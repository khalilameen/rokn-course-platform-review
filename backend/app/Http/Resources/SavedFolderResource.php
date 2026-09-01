<?php

namespace App\Http\Resources;

use App\Services\BunnyService;
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function resolveFolderImage(): string
    {
        $firstLesson = $this->relationLoaded('lessons')
            ? $this->lessons->first()
            : $this->lessons()->with('course')->orderByPivot('created_at')->first();

        if ($firstLesson) {
            $lessonImage = trim((string) ($firstLesson->thumbnail_path ?: $firstLesson->image));
            if ($lessonImage !== '') {
                $signed = app(BunnyService::class)->generateBunnySignedUrl($lessonImage);
                if ($signed) {
                    return $signed;
                }

                // Older authoring records store a public thumbnail path
                // rather than a Bunny media identifier.
                return $lessonImage;
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
