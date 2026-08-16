<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Audits published learning media without deleting or unpublishing anything.
 * Playback readiness and operational integrity are deliberately separate: a
 * missing thumbnail must be visible to operators without blocking a playable
 * lesson for learners.
 */
final class MediaReconciliationService
{
    private ?bool $integritySchemaReady = null;

    public function __construct(
        private BunnyService $bunny,
        private MediaHealthService $health
    ) {
    }

    /** @return array<string, mixed> */
    public function reconcileCourse(
        Course $course,
        bool $persist = true,
        bool $fetchManifest = true
    ): array {
        $course->loadMissing([
            'photo',
            'lessons.mediaState',
            'modules.attachments',
            'sections.attachments',
        ]);

        $courseIssues = $this->courseIssues($course);
        $results = [];
        foreach ($course->lessons as $lesson) {
            $results[] = $this->inspectLesson(
                $lesson,
                $courseIssues,
                $persist,
                $fetchManifest
            );
        }

        $counts = ['healthy' => 0, 'attention' => 0, 'quarantined' => 0];
        $issueCount = count($courseIssues);
        foreach ($results as $result) {
            $status = (string) ($result['integrity_status'] ?? 'attention');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $issueCount += collect((array) ($result['issues'] ?? []))
                ->where('scope', 'lesson')
                ->count();
        }

        // A published course with no lesson is operationally incomplete even
        // though there is no media-state row on which to persist that fact.
        if ($course->lessons->isEmpty()) {
            $courseIssues[] = $this->issue('course_has_no_lessons', 'attention', 'course', (int) $course->id);
            $issueCount++;
        }

        return [
            'course_id' => (int) $course->id,
            'lessons' => count($results),
            'counts' => $counts,
            'issues' => $issueCount,
            'course_issues' => $courseIssues,
            'results' => $results,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $courseIssues
     * @return array<string, mixed>
     */
    private function inspectLesson(
        Lesson $lesson,
        array $courseIssues,
        bool $persist,
        bool $fetchManifest
    ): array {
        $issues = $courseIssues;
        $state = $lesson->mediaState;

        if (!$lesson->usesBunnyVideo()) {
            $issues[] = $this->issue('missing_secure_source', 'quarantined', 'lesson', (int) $lesson->id);
            if ($persist) {
                $state = $this->health->probe($lesson);
            }
            return $this->completeLessonResult($lesson, $state, $issues, $persist);
        }

        if ($persist) {
            // The existing probe remains the single writer of playback health.
            // Integrity fields below never overwrite its ready/failed state.
            $state = $this->health->probe($lesson);
        } else {
            $state = $this->readOnlyState($lesson);
        }

        $playbackStatus = (string) ($state?->status ?: 'unknown');
        if ((string) ($state?->last_error_code ?? '') === 'provider_unreachable') {
            $issues[] = $this->issue('provider_unreachable', 'attention', 'lesson', (int) $lesson->id);
        } elseif ($playbackStatus === 'failed') {
            $issues[] = $this->issue('provider_encode_failed', 'quarantined', 'lesson', (int) $lesson->id);
        } elseif ($playbackStatus !== 'ready') {
            $issues[] = $this->issue(
                $playbackStatus === 'processing' ? 'provider_still_processing' : 'provider_unreachable',
                'attention',
                'lesson',
                (int) $lesson->id
            );
        }

        if ((int) ($state?->duration_seconds ?? 0) <= 0) {
            $issues[] = $this->issue('duration_missing', 'attention', 'lesson', (int) $lesson->id);
        } else {
            $declaredDuration = max(0, (int) $lesson->duration_minutes * 60);
            $providerDuration = (int) $state->duration_seconds;
            $tolerance = max(15, (int) round($providerDuration * 0.20));
            if ($declaredDuration > 0 && abs($declaredDuration - $providerDuration) > $tolerance) {
                $issues[] = $this->issue('duration_mismatch', 'attention', 'lesson', (int) $lesson->id);
            }
        }

        $qualities = collect((array) ($state?->available_qualities ?? []))
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true));
        if ($qualities->isEmpty()) {
            $issues[] = $this->issue('quality_ladder_missing', 'attention', 'lesson', (int) $lesson->id);
        }

        $thumbnail = trim((string) $lesson->thumbnail_path);
        $providerThumbnail = trim((string) data_get($state?->manifest, 'thumbnail_file_name'));
        if ($thumbnail === '' && $providerThumbnail === '') {
            $issues[] = $this->issue('thumbnail_unverified', 'attention', 'lesson', (int) $lesson->id);
        }

        $source = $this->bunny->getVideo((string) $lesson->bunny_video_id);
        if (!$source || trim((string) ($source['url'] ?? '')) === '') {
            $issues[] = $this->issue('signed_manifest_unavailable', 'quarantined', 'lesson', (int) $lesson->id);
        } elseif ($fetchManifest) {
            $manifestResult = $this->manifestIsReadable((string) $source['url']);
            if (!$manifestResult['ready']) {
                $issues[] = $this->issue(
                    (string) $manifestResult['code'],
                    $playbackStatus === 'ready' ? 'attention' : 'quarantined',
                    'lesson',
                    (int) $lesson->id
                );
            }
        }

        return $this->completeLessonResult($lesson, $state, $issues, $persist);
    }

    private function readOnlyState(Lesson $lesson): LessonMediaState
    {
        $state = $lesson->mediaState ?: new LessonMediaState([
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_media_id' => $lesson->bunny_video_id,
            'protocol' => 'hls',
        ]);
        $details = $this->bunny->getRemoteVideoDetails((string) $lesson->bunny_video_id);
        if (!$details) {
            $state->forceFill([
                'status' => $state->status ?: 'unknown',
                'last_error_code' => 'provider_unreachable',
            ]);
            return $state;
        }

        $resolutions = collect(explode(',', (string) ($details['availableResolutions'] ?? '')))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();
        $ready = (int) ($details['status'] ?? -1) === 4
            || (float) ($details['encodeProgress'] ?? 0) >= 100
            || $resolutions->isNotEmpty();
        $failed = (int) ($details['status'] ?? -1) === 6;
        $qualities = $resolutions
            ->map(fn ($value) => str_ends_with($value, 'p') ? $value : $value . 'p')
            ->filter(fn ($value) => in_array($value, ['1080p', '720p', '480p', '360p'], true))
            ->prepend('auto')
            ->unique()
            ->values()
            ->all();

        $state->forceFill([
            'status' => $failed ? 'failed' : ($ready ? 'ready' : 'processing'),
            'duration_seconds' => isset($details['length'])
                ? max(0, (int) round((float) $details['length']))
                : $state->duration_seconds,
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
        ]);

        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function completeLessonResult(
        Lesson $lesson,
        ?LessonMediaState $state,
        array $issues,
        bool $persist
    ): array {
        $issues = collect($issues)
            ->unique(fn (array $issue) => implode(':', [
                $issue['code'] ?? '',
                $issue['scope'] ?? '',
                $issue['reference'] ?? '',
            ]))
            ->values()
            ->take(50)
            ->all();

        $integrityStatus = 'healthy';
        if (collect($issues)->contains(fn (array $issue) => ($issue['severity'] ?? '') === 'quarantined')) {
            $integrityStatus = 'quarantined';
        } elseif ($issues !== []) {
            $integrityStatus = 'attention';
        }

        if (
            $persist
            && $state
            && $this->integritySchemaReady()
        ) {
            $state->forceFill([
                'integrity_status' => $integrityStatus,
                'integrity_issues' => $issues ?: null,
                'last_reconciled_at' => now(),
                'quarantined_at' => $integrityStatus === 'quarantined'
                    ? ($state->quarantined_at ?: now())
                    : null,
            ])->save();
        }

        return [
            'lesson_id' => (int) $lesson->id,
            'playback_status' => (string) ($state?->status ?: 'unknown'),
            'integrity_status' => $integrityStatus,
            'issues' => $issues,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function courseIssues(Course $course): array
    {
        $issues = [];
        if (!$course->photo && trim((string) $course->getRawOriginal('image')) === '') {
            $issues[] = $this->issue('course_cover_missing', 'attention', 'course', (int) $course->id);
        }

        foreach ($course->modules as $module) {
            foreach ($module->attachments as $attachment) {
                if (!$this->attachmentExists($attachment)) {
                    $issues[] = $this->issue('attachment_missing', 'attention', 'module', (int) $module->id);
                }
            }
            $external = trim((string) $module->attachments_link);
            if ($external !== '' && SafeExternalUrl::sanitize($external) === null) {
                $issues[] = $this->issue('external_attachment_url_invalid', 'attention', 'module', (int) $module->id);
            }
        }

        foreach ($course->sections as $section) {
            foreach ($section->attachments as $attachment) {
                if (!$this->attachmentExists($attachment)) {
                    $issues[] = $this->issue('attachment_missing', 'attention', 'section', (int) $section->id);
                }
            }
        }

        return collect($issues)
            ->unique(fn (array $issue) => implode(':', [$issue['code'], $issue['scope'], $issue['reference']]))
            ->values()
            ->all();
    }

    private function attachmentExists(Attachment $attachment): bool
    {
        $path = trim((string) $attachment->file_path);
        $disk = trim((string) $attachment->storage_disk);
        if ($path === '' || $disk === '' || !config("filesystems.disks.{$disk}")) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable $exception) {
            report($exception);
            return false;
        }
    }

    private function integritySchemaReady(): bool
    {
        if ($this->integritySchemaReady === null) {
            $this->integritySchemaReady = Schema::hasTable('lesson_media_states')
                && Schema::hasColumn('lesson_media_states', 'integrity_status');
        }

        return $this->integritySchemaReady;
    }

    /** @return array{ready:bool,code:string} */
    private function manifestIsReadable(string $signedUrl): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.apple.mpegurl'])
                ->get($signedUrl);

            if (!$response->successful()) {
                return ['ready' => false, 'code' => 'manifest_http_error'];
            }

            $prefix = substr((string) $response->body(), 0, 8192);
            return str_contains($prefix, '#EXTM3U')
                ? ['ready' => true, 'code' => 'ok']
                : ['ready' => false, 'code' => 'manifest_invalid'];
        } catch (Throwable $exception) {
            // Never log the signed URL or its token.
            return ['ready' => false, 'code' => 'manifest_unreachable'];
        }
    }

    /** @return array{code:string,severity:string,scope:string,reference:int} */
    private function issue(string $code, string $severity, string $scope, int $reference): array
    {
        return compact('code', 'severity', 'scope', 'reference');
    }
}
