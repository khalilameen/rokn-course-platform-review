<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonMediaState;

final class MediaHealthService
{
    public function __construct(private BunnyService $bunny) {}

    public function probe(Lesson $lesson): LessonMediaState
    {
        $state = LessonMediaState::query()->firstOrNew(['lesson_id' => $lesson->id]);
        $state->forceFill([
            'provider' => 'bunny',
            'provider_media_id' => $lesson->bunny_video_id,
            'protocol' => 'hls',
            'last_probe_at' => now(),
        ]);
        if (!$lesson->usesBunnyVideo()) {
            $state->forceFill(['status' => 'failed', 'last_error_code' => 'missing_source', 'last_error_message' => 'No Bunny media id is attached to this lesson.'])->save();
            return $state;
        }

        $details = $this->bunny->getRemoteVideoDetails((string) $lesson->bunny_video_id);
        if (!$details) {
            $state->forceFill([
                'status' => $state->exists ? $state->status : 'unknown',
                'last_error_code' => 'provider_unreachable',
                'last_error_message' => 'Bunny control plane did not return media details.',
                'retry_count' => (int) $state->retry_count + 1,
            ])->save();
            return $state;
        }

        $resolutions = collect(explode(',', (string) ($details['availableResolutions'] ?? '')))
            ->map(fn ($value) => trim($value))->filter()->values();
        $ready = (int) ($details['status'] ?? -1) === 4
            || (float) ($details['encodeProgress'] ?? 0) >= 100
            || $resolutions->isNotEmpty();
        $failed = (int) ($details['status'] ?? -1) === 6;
        $qualities = $resolutions
            ->map(fn ($value) => str_ends_with($value, 'p') ? $value : $value . 'p')
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true))
            ->prepend('auto')->unique()->values()->all();

        $state->forceFill([
            'status' => $failed ? 'failed' : ($ready ? 'ready' : 'processing'),
            'duration_seconds' => isset($details['length']) ? max(0, (int) round((float) $details['length'])) : $state->duration_seconds,
            'available_qualities' => $qualities ?: ['auto'],
            'manifest' => [
                'status' => $details['status'] ?? null,
                'encode_progress' => $details['encodeProgress'] ?? null,
                'width' => $details['width'] ?? null,
                'height' => $details['height'] ?? null,
                'available_resolutions' => $resolutions->all(),
                'thumbnail_file_name' => $details['thumbnailFileName'] ?? null,
            ],
            'last_error_code' => $failed ? 'provider_failed' : null,
            'last_error_message' => $failed ? 'Bunny reported a failed encode.' : null,
            'retry_count' => $failed ? (int) $state->retry_count + 1 : 0,
        ])->save();
        return $state;
    }
}
