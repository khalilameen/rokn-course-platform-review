<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PlaybackManifestService
{
    public function __construct(
        private BunnyService $bunny,
        private CourseModuleAccessService $access,
        private MediaHealthService $mediaHealth,
        private PlaybackCapabilityService $capabilities
    ) {
    }

    public function issue(User $user, Lesson $lesson, array $clientContext = []): array
    {
        $lesson->loadMissing(['courseSection.module', 'course']);
        $section = $lesson->courseSection;
        $course = $lesson->course;
        $allowed = (bool) $lesson->is_opened;
        if (!$allowed && $course && $section?->module) {
            $allowed = $this->access->canAccessModule($user, $course, $section->module);
        } elseif (!$allowed && $course) {
            $allowed = $this->access->hasCourseAccess($user, $course);
        }
        if (!$allowed) {
            throw new AuthorizationException('This lesson is not available yet.');
        }
        if (!$lesson->usesBunnyVideo()) {
            throw new RuntimeException('The lesson video is not ready for secure playback.');
        }

        $source = $this->bunny->getVideo((string) $lesson->bunny_video_id);
        if (!$source || empty($source['url'])) {
            throw new RuntimeException('A secure playback source could not be issued.');
        }

        // createOrFirst catches the unique-key race when a newly promoted
        // lesson receives its first concurrent plays on multiple workers.
        $state = LessonMediaState::query()->createOrFirst(
            ['lesson_id' => $lesson->id],
            [
                'provider' => 'bunny',
                'provider_media_id' => $lesson->bunny_video_id,
                'status' => 'ready',
                'protocol' => 'hls',
                'duration_seconds' => max(0, (int) $lesson->duration_minutes * 60) ?: null,
                'available_qualities' => ['auto'],
            ]
        );

        if ($state->provider_media_id !== $lesson->bunny_video_id) {
            $state->forceFill([
                'provider_media_id' => $lesson->bunny_video_id,
                'status' => 'ready',
                'protocol' => 'hls',
                'available_qualities' => ['auto'],
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        }

        if ($state->status !== 'ready' && (!$state->last_probe_at || $state->last_probe_at->lt(now()->subSeconds(30)))) {
            $state = $this->mediaHealth->probe($lesson);
        }

        if ($state->status !== 'ready') {
            throw new RuntimeException('The lesson video is still being prepared.');
        }

        $qualities = collect($state->available_qualities ?: ['auto'])
            ->filter(fn ($quality) => in_array($quality, ['auto', '1080p', '720p', '480p', '360p'], true))
            ->unique()->values()->all();
        if (!in_array('auto', $qualities, true)) {
            array_unshift($qualities, 'auto');
        }

        $clientCapabilities = $this->capabilities->normalize(
            isset($clientContext['client_capabilities']) && is_array($clientContext['client_capabilities'])
                ? $clientContext['client_capabilities']
                : null
        );
        $networkPolicy = $this->capabilities->networkPolicy($clientCapabilities);
        $playbackReason = $this->capabilities->playbackReason($clientCapabilities, $networkPolicy);
        $sessionAttributes = $this->capabilities->sessionAttributes(
            $clientCapabilities,
            isset($clientContext['client']) ? (string) $clientContext['client'] : null,
            $playbackReason
        );

        $sourceExpiresAt = null;
        if (!empty($source['expires_at'])) {
            try {
                $sourceExpiresAt = Carbon::parse((string) $source['expires_at']);
            } catch (\Throwable) {
                $sourceExpiresAt = null;
            }
        }
        $refreshAfter = $this->refreshAfter($sourceExpiresAt);
        $sessionAttributes['source_expires_at'] = $sourceExpiresAt;

        $session = DB::transaction(function () use (
            $user,
            $lesson,
            $section,
            $source,
            $sessionAttributes,
            $clientContext
        ): PlaybackSession {
            // Serialize only the tiny session-allocation section. A network
            // retry before the first heartbeat reuses the same session, while
            // an actual second player (which has started heartbeating) gets a
            // separate session and independent sequence numbers.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $requestedSessionId = (string) ($clientContext['playback_session_id'] ?? '');
            if ($requestedSessionId !== '') {
                $requested = PlaybackSession::query()
                    ->whereKey($requestedSessionId)
                    ->where('user_id', $user->id)
                    ->where('lesson_id', $lesson->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->first();
                if ($requested) {
                    $requested->forceFill($sessionAttributes)->save();

                    return $requested;
                }
            }

            $recent = PlaybackSession::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->whereNull('ended_at')
                ->whereNull('last_heartbeat_at')
                ->where('last_sequence', 0)
                ->where('started_at', '>=', now()->subSeconds(30))
                ->latest('started_at')
                ->first();
            if ($recent) {
                $recent->forceFill($sessionAttributes)->save();

                return $recent;
            }

            return PlaybackSession::query()->create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'course_section_id' => $section?->id,
                'started_at' => now(),
                'event_type' => 'play',
                'source_protocol' => 'hls',
                'source_host' => parse_url((string) $source['url'], PHP_URL_HOST),
            ] + $sessionAttributes);
        }, 3);

        $expiresInSeconds = $sourceExpiresAt
            ? (int) max(0, now()->diffInSeconds($sourceExpiresAt, false))
            : null;

        return [
            'playback_session_id' => $session->id,
            'lesson_id' => $lesson->id,
            'source_url' => $source['url'],
            'fallback_url' => null,
            'fallback_reason' => 'provider_has_no_independent_stream_only_fallback',
            'fallback' => [
                'available' => false,
                'url' => null,
                'reason' => 'provider_has_no_independent_stream_only_fallback',
            ],
            'protocol' => 'hls',
            'expires_at' => $source['expires_at'] ?? null,
            'expires_in_seconds' => $expiresInSeconds,
            'refresh_after' => $refreshAfter?->toIso8601String(),
            'duration_seconds' => $state->duration_seconds,
            'available_qualities' => $qualities,
            'quality_sources' => (object) [],
            'quality_source_reason' => 'adaptive_master_manifest',
            'playback_reason' => $playbackReason,
            'network_policy' => $networkPolicy,
            'media_status' => $state->status,
        ];
    }

    private function refreshAfter(?Carbon $expiresAt): ?Carbon
    {
        if (!$expiresAt || $expiresAt->isPast()) {
            return null;
        }

        $margin = max(60, min(3600, (int) config('playback.manifest_refresh_margin_seconds', 900)));
        $refreshAt = $expiresAt->copy()->subSeconds($margin);

        return $refreshAt->isAfter(now()->addMinute())
            ? $refreshAt
            : now()->addMinute();
    }
}
