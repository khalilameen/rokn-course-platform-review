<?php

namespace App\Http\Resources;

use App\Services\BunnyService;
use App\Support\BusinessClock;
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
        $providerSeconds = $this->relationLoaded('mediaState')
            ? max(0, (int) ($this->mediaState?->duration_seconds ?? 0))
            : 0;
        $durationSeconds = $providerSeconds > 0
            ? $providerSeconds
            : max(0, (int) $this->duration_minutes) * 60;
        $thumbnail = trim((string) ($this->thumbnail_path ?: $this->image));
        $image = $thumbnail !== ''
            ? app(BunnyService::class)->generateBunnySignedUrl($thumbnail) ?: $thumbnail
            : null;
        $image ??= $this->course?->image ? (string) $this->course->image : null;
        $image ??= asset('images/default-folder.png');

        return [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'duration_minutes' => (int)$this->duration_minutes,
            'duration_seconds' => $durationSeconds,
            'description' => (string)$this->description,
            'is_opened' => (bool)$this->is_opened,
            'image' => $image,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'saved_at' => $this->when(
                array_key_exists('saved_at', $this->getAttributes()),
                fn () => BusinessClock::toUtc(
                    (string) $this->getAttribute('saved_at')
                )?->toIso8601String()
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
