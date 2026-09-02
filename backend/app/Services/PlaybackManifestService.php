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
        private CourseCompletionService $completion,
        private PlaybackCapabilityService $capabilities
    ) {
    }

    public function issue(User $user, Lesson $lesson, array $clientContext = []): array
    {
        [$lesson, $state] = DB::transaction(function () use ($lesson): array {
            // Resolve the current media generation under the same short lock
            // that synchronizes its durable readiness row. A route-bound model
            // or an earlier request may still carry the superseded GUID.
            $current = Lesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $current->loadMissing(['courseSection.module', 'course']);
            $state = LessonMediaState::query()->createOrFirst(
                ['lesson_id' => $current->id],
                [
                    'provider' => 'bunny',
                    'provider_media_id' => $current->bunny_video_id,
                    'status' => 'unknown',
                    'protocol' => 'hls',
                    'duration_seconds' => max(0, (int) $current->duration_minutes * 60) ?: null,
                    'available_qualities' => ['auto'],
                ]
            );
            $state = LessonMediaState::query()
                ->where('lesson_id', $current->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $state->provider_media_id !== (string) $current->bunny_video_id) {
                $state->forceFill(LessonMediaState::resetForGeneration(
                    (string) $current->bunny_video_id,
                    'unknown'
                ))->save();
            }

            return [$current, $state];
        }, 3);

        $section = $lesson->courseSection;
        $course = $lesson->course;

        // Route model binding can still resolve a legacy Lesson after its
        // section/course was removed from the authored graph. Never let the
        // preview flag resurrect orphaned or unpublished media.
        if (
            !$course
            || !$section
            || !$course->isPublishedForLearning()
            || (int) $lesson->list_id !== (int) $course->id
            || (int) $section->course_id !== (int) $course->id
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            throw new AuthorizationException('This lesson is not published.');
        }

        $allowed = (bool) $lesson->is_opened && !$course->isNestedCourse();
        if (!$allowed) {
            $allowed = $this->completion->canAccessSection($user, $section);
        }
        if (!$allowed) {
            throw new AuthorizationException('This lesson is not available yet.');
        }
        if (!$lesson->usesBunnyVideo()) {
            throw new RuntimeException('The lesson video is not ready for secure playback.');
        }

        // Bunny's control-plane API is deliberately kept out of the playback
        // request. The scheduled media reconciliation owns remote probes;
        // learners receive the last durable readiness result immediately.
        // A stale `ready` row is still playable because the signed delivery
        // URL is the data-plane source of truth, while unknown/processing rows
        // fail closed until reconciliation promotes them.
        if ($state->status !== 'ready') {
            throw new RuntimeException('The lesson video is still being prepared.');
        }

        // Signing is stateless and remains outside the database transaction.
        // Superseded objects are retained for seven days, so a manifest issued
        // immediately before an authoring replacement stays valid for its TTL.
        $source = $this->bunny->getVideo((string) $lesson->bunny_video_id);
        if (!$source || empty($source['url'])) {
            throw new RuntimeException('A secure playback source could not be issued.');
        }
        $fallback = $this->bunny->getFallbackVideo((string) $lesson->bunny_video_id);

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
                    // A screen can remain in the Android/iOS back stack for
                    // hours. Never renew the signed source onto a session that
                    // the evidence endpoint will reject as expired; close it
                    // and allocate one fresh sequence namespace instead.
                    if ($requested->started_at?->lt(now()->subHours(12))) {
                        $requested->forceFill([
                            'event_type' => 'stop',
                            'ended_at' => now(),
                            'end_reason' => 'session_expired',
                        ])->save();
                    } else {
                        $requested->forceFill($sessionAttributes)->save();

                        return $requested;
                    }
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
        $refreshInSeconds = $refreshAfter
            ? (int) max(0, now()->diffInSeconds($refreshAfter, false))
            : null;
        $poster = null;
        if (trim((string) $lesson->thumbnail_path) !== '') {
            $poster = $this->bunny->generateBunnySignedUrl(
                (string) $lesson->thumbnail_path,
                max(600, (int) ($expiresInSeconds ?: 3600))
            );
        } else {
            $providerThumbnail = trim((string) data_get($state->manifest, 'thumbnail_file_name'));
            if ($providerThumbnail !== '') {
                $poster = $this->bunny->getVideoThumbnail(
                    (string) $lesson->bunny_video_id,
                    $providerThumbnail
                )['url'] ?? null;
            }
        }

        return [
            'playback_session_id' => $session->id,
            'lesson_id' => $lesson->id,
            'source_url' => $source['url'],
            'fallback_url' => $fallback['url'] ?? null,
            'fallback_reason' => $fallback
                ? 'independent_cdn_hostname'
                : 'provider_has_no_independent_stream_only_fallback',
            'fallback' => [
                'available' => $fallback !== null,
                'url' => $fallback['url'] ?? null,
                'reason' => $fallback
                    ? 'independent_cdn_hostname'
                    : 'provider_has_no_independent_stream_only_fallback',
            ],
            'protocol' => 'hls',
            'expires_at' => $source['expires_at'] ?? null,
            'expires_in_seconds' => $expiresInSeconds,
            'refresh_after' => $refreshAfter?->toIso8601String(),
            'refresh_in_seconds' => $refreshInSeconds,
            'poster_url' => $poster,
            'poster_expires_at' => $poster ? ($source['expires_at'] ?? null) : null,
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
