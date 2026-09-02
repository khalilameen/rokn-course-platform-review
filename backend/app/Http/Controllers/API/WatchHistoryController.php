<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\WatchingLog;
use App\Services\CourseChatAccessService;
use App\Services\CourseStagedAuthoringService;
use App\Services\BunnyService;
use App\Services\LearningEvidenceService;
use App\Services\LearningRewardService;
use App\Services\PlaybackCapabilityService;
use App\Services\PlaybackSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WatchHistoryController extends Controller
{
    public function __construct(
        private readonly LearningEvidenceService $learningEvidence,
        private readonly CourseChatAccessService $courseAccess,
        private readonly CourseStagedAuthoringService $stagedAuthoring,
        private readonly BunnyService $bunny
    ) {
    }

    /**
     * Return a bounded, resume-oriented history. Video URLs are deliberately
     * excluded so this endpoint cannot bypass the normal lesson access path.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
        ]);

        $user = auth('api')->user();
        $this->materializeWatchHistory((int) $user->id);
        $history = WatchingLog::query()
            ->where('user_id', $user->id)
            ->whereHas('course', function ($courses): void {
                $courses->where('is_coming_soon', false)
                    ->whereNull('parent_id')
                    ->whereHas('sections')
                    ->whereDoesntHave('courseSection');
            })
            ->whereHas('lesson.courseSection', function ($sections): void {
                $sections->whereColumn('course_sections.course_id', 'watching_logs.course_id');
            })
            ->when(isset($validated['course_id']), function ($query) use ($validated) {
                $query->where('course_id', $validated['course_id']);
            })
            ->with([
                'lesson:id,list_id,title,title_ar,title_en,thumbnail_path,duration_minutes',
                'lesson.mediaState:id,lesson_id,duration_seconds',
                'course:id,name_ar,name_en,image',
                'courseSection:id,course_id,module_id,sectionable_type,sectionable_id,order,title,title_ar,title_en',
            ])
            ->orderByRaw('COALESCE(watched_at, updated_at) DESC')
            ->orderByDesc('watching_logs.id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل سجل المشاهدة',
            'data' => [
                'tracking_enabled' => (bool) $user->watch_history_enabled,
                'items' => collect($history->items())->map(function (WatchingLog $log) {
                    $duration = max(
                        0,
                        (int) ($log->duration_seconds ?? 0),
                        (int) ($log->lesson?->mediaState?->duration_seconds ?? 0),
                        max(0, (int) ($log->lesson?->duration_minutes ?? 0)) * 60
                    );
                    $progress = $duration && $duration > 0
                        ? min(100, round(($log->position_seconds / $duration) * 100, 2))
                        : null;
                    $thumbnailPath = trim((string) $log->lesson?->thumbnail_path);
                    $thumbnail = $thumbnailPath !== ''
                        ? $this->bunny->generateBunnySignedUrl($thumbnailPath)
                        : null;

                    return [
                        'id' => $log->id,
                        'course_id' => $log->course_id,
                        'course_title' => $log->course?->name_ar ?? $log->course_name,
                        'course_title_en' => $log->course?->name_en,
                        'course_image' => $log->course?->image,
                        'lesson_id' => $log->lesson_id,
                        'lesson_title' => $log->lesson?->title ?? $log->lesson_name,
                        'lesson_thumbnail' => $thumbnail ?: $log->course?->image,
                        'course_section_id' => $log->course_section_id,
                        'section_order' => $log->courseSection?->order,
                        'position_seconds' => $log->position_seconds,
                        'duration_seconds' => $duration,
                        'progress_percentage' => $progress,
                        'is_completed' => $log->completed_at !== null,
                        'watched_at' => $log->watched_at ?? $log->updated_at,
                    ];
                })->values(),
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'last_page' => $history->lastPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                ],
            ],
        ]);
    }

    /** Canonicalize resume pointers lazily, outside the course publish lock. */
    private function materializeWatchHistory(int $userId): void
    {
        $logs = WatchingLog::query()->where('user_id', $userId)
            ->get(['id', 'lesson_id', 'course_id', 'course_section_id', 'position_seconds',
                'duration_seconds', 'playback_session_id', 'playback_session_started_at',
                'last_playback_sequence', 'watched_at', 'completed_at', 'created_at', 'updated_at']);
        if ($logs->isEmpty()) return;
        $current = $this->stagedAuthoring->currentLearnerEntityMap(
            Lesson::class,
            $logs->pluck('lesson_id')
        );
        $currentSections = \App\Models\CourseSection::query()
            ->where('sectionable_type', Lesson::class)
            ->whereIn('sectionable_id', collect($current)->values())
            ->get(['id', 'course_id', 'sectionable_id'])
            ->keyBy('sectionable_id');
        foreach ($logs as $source) {
            $targetLessonId = (int) ($current[(int) $source->lesson_id] ?? $source->lesson_id);
            $targetSection = $currentSections->get($targetLessonId);
            if ($targetLessonId === (int) $source->lesson_id || !$targetSection) continue;
            DB::transaction(function () use ($source, $targetLessonId, $targetSection, $userId): void {
                $locked = WatchingLog::query()->whereKey($source->id)->lockForUpdate()->first();
                if (!$locked) return;

                // Another history request may have canonicalized this exact
                // row while we waited. Never treat that now-current row as
                // both source and target and delete it during the merge.
                if ((int) $locked->lesson_id !== (int) $source->lesson_id) return;

                DB::table('watching_logs')->insertOrIgnore([
                    'user_id' => $userId,
                    'lesson_id' => $targetLessonId,
                    'lesson_name' => $locked->lesson_name,
                    'course_id' => (int) $targetSection->course_id,
                    'course_section_id' => (int) $targetSection->id,
                    'course_name' => $locked->course_name,
                    'position_seconds' => $locked->position_seconds,
                    'duration_seconds' => $locked->duration_seconds,
                    'playback_session_id' => $locked->playback_session_id,
                    'playback_session_started_at' => $locked->playback_session_started_at,
                    'last_playback_sequence' => $locked->last_playback_sequence,
                    'watched_at' => $locked->watched_at,
                    'completed_at' => $locked->completed_at,
                    'created_at' => $locked->created_at,
                    'updated_at' => $locked->updated_at,
                ]);
                $target = WatchingLog::query()->where('user_id', $userId)
                    ->where('lesson_id', $targetLessonId)->lockForUpdate()->first();
                if (!$target) throw new \RuntimeException('Canonical watch history row was not persisted.');
                $sourceTime = $locked->watched_at ?? $locked->updated_at;
                $targetTime = $target->watched_at ?? $target->updated_at;
                if ($sourceTime && (!$targetTime || $sourceTime->gt($targetTime))) {
                    $target->forceFill([
                        'position_seconds' => $locked->position_seconds,
                        'duration_seconds' => $locked->duration_seconds,
                        'playback_session_id' => $locked->playback_session_id,
                        'playback_session_started_at' => $locked->playback_session_started_at,
                        'last_playback_sequence' => $locked->last_playback_sequence,
                        'watched_at' => $locked->watched_at,
                    ]);
                }
                $target->forceFill([
                    'lesson_name' => (string) ($target->lesson_name ?: $locked->lesson_name),
                    'course_id' => (int) $targetSection->course_id,
                    'course_section_id' => (int) $targetSection->id,
                    'course_name' => (string) ($target->course_name ?: $locked->course_name),
                ]);
                $target->completed_at ??= $locked->completed_at;
                $target->save();
                $locked->delete();
            }, 3);
        }
    }

    /** Record the latest position without changing academic completion state. */
    public function store(
        Request $request,
        LearningRewardService $rewards,
        PlaybackSessionService $sessions
    ): JsonResponse
    {
        $validated = $request->validate([
            'lesson_id' => 'required|integer|exists:lessons,id',
            'position_seconds' => 'nullable|integer|min:0|max:86400',
            'duration_seconds' => 'nullable|integer|min:1|max:86400',
            'is_completed' => 'nullable|boolean',
            'playback_session_id' => 'nullable|uuid',
            'sequence' => 'nullable|required_with:playback_session_id|integer|min:1|max:2147483647',
            'event_type' => 'nullable|string|in:play,start,heartbeat,pause,background,stop,complete,error',
            'end_reason' => 'nullable|string|in:user_exit,navigation,lesson_changed,'
                . 'app_closed,playback_error,source_expired,replaced,unknown',
            'is_terminal' => 'nullable|boolean',
            'effective_quality' => 'nullable|string|in:auto,1080p,720p,480p,360p',
            'effective_bitrate_kbps' => 'nullable|integer|min:0|max:100000',
            'playback_rate' => 'nullable|numeric|min:0.5|max:2',
            'recovery_count' => 'nullable|integer|min:0|max:20',
            'startup_latency_ms' => 'nullable|integer|min:0|max:120000',
            'buffer_count' => 'nullable|integer|min:0|max:500',
            'buffer_duration_ms' => 'nullable|integer|min:0|max:3600000',
            'error_code' => 'nullable|string|max:64',
            'diagnostics' => 'nullable|array|max:12',
        ] + PlaybackCapabilityService::validationRules());

        $user = auth('api')->user();

        $lesson = Lesson::with([
            'course:id,name_ar,name_en,is_coming_soon,parent_id',
            'courseSection',
            'mediaState:id,lesson_id,duration_seconds',
        ])->findOrFail($validated['lesson_id']);
        $courseId = (int) $lesson->list_id;
        $course = $lesson->course;
        $section = $lesson->courseSection;
        $archiveRevision = $course && $course->is_coming_soon
            ? $this->stagedAuthoring->activeArchiveForCourse($course)
            : null;
        $archiveContinuation = $archiveRevision
            ? $this->stagedAuthoring->archivedPlaybackContinuation(
                $user,
                $lesson,
                $validated['playback_session_id'] ?? null
            )
            : null;
        if ($archiveRevision && !$archiveContinuation) {
            $canonical = $archiveRevision->canonicalCourse()->firstOrFail();
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'course_revision_changed',
                'message' => "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
                'data' => [
                    'course_id' => (int) $canonical->id,
                    'published_revision' => (int) (
                        $canonical->last_published_authoring_version ?: $canonical->authoring_version
                    ),
                    'reload_endpoint' => "/api/v1/courses/{$canonical->id}/details",
                ],
            ], 409);
        }
        if ($archiveContinuation) {
            $courseId = (int) $archiveContinuation['canonical_course']->id;
        }

        if (
            !$course
            || !$section
            || (!$course->isPublishedForLearning() && !$archiveContinuation)
            || (int) $section->course_id !== (int) $lesson->list_id
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المقطع غير متاح',
                'data' => null,
            ], 404);
        }

        $isPublicPreview = (bool) $lesson->is_opened && !$course->isNestedCourse();
        // Publish grace preserves an already-open media generation, not a
        // revoked purchase. Re-check the canonical entitlement on every
        // heartbeat so an archived session cannot outlive access removal.
        if (
            !$isPublicPreview
            && !$this->courseAccess->hasLearningAccess((int) $user->id, $courseId)
        ) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'افتح الكورس أولًا لحفظ تقدمك',
                'data' => null,
            ], 403);
        }

        $providerDuration = max(0, (int) ($lesson->mediaState?->duration_seconds ?? 0));
        // Resume and session metrics use the reconciled media duration when it
        // exists. A malformed player payload must not shrink the timeline,
        // report a false 100%, or terminate evidence against a fake length.
        $duration = $providerDuration > 0
            ? $providerDuration
            : ($validated['duration_seconds'] ?? null);
        $position = $validated['position_seconds'] ?? 0;
        if ($duration !== null) {
            $position = min($position, $duration);
        }

        $sessionResult = $sessions->accept($user, (int) $lesson->id, $validated + [
            'position_seconds' => $position,
            'duration_seconds' => $duration,
        ]);
        if (!$sessionResult['accepted']) {
            $invalid = $sessionResult['reason'] === 'invalid_session';
            return response()->json([
                'status' => $invalid ? 422 : 200,
                'success' => !$invalid,
                'message' => $invalid
                    ? 'تعذّر حفظ تقدم هذا المقطع'
                    : 'تم حفظ هذا الجزء من قبل',
                'data' => [
                    'recorded' => false,
                    'duplicate' => !$invalid,
                    'reason' => $sessionResult['reason'],
                    'credited_seconds' => 0,
                ],
            ], $invalid ? 422 : 200);
        }

        // Academic evidence is deliberately separate from optional watch
        // history. Disabling the user's resume list never disables progression,
        // and the server credits only time-compatible heartbeats.
        $evidence = ($sessionResult['trusted_evidence'] ?? false)
            ? $this->learningEvidence->recordHeartbeat(
                $user,
                $lesson,
                $position,
                $duration,
                $sessionResult['previous_sample'] ?? null
            )
            : [
                'evidence_id' => null,
                'verified_seconds' => 0,
                'required_seconds' => null,
                'credited_seconds' => 0,
                'eligible_for_completion' => false,
            ];
        $currentLesson = $archiveContinuation['current_lesson'] ?? null;
        $currentEvidence = $currentLesson
            ? $this->learningEvidence->carryCompletedRevisionForward(
                $user,
                $lesson,
                $currentLesson,
                $evidence
            )
            : null;

        if (!$user->watch_history_enabled) {
            $reward = $rewards->recordStudy($user, (int) $evidence['credited_seconds']);

            return response()->json([
                'status' => 200,
                'success' => true,
            'message' => 'تم حفظ تقدمك',
                'data' => [
                    'recorded' => false,
                    'tracking_enabled' => false,
                    'learning_evidence' => $evidence,
                    'current_learning_evidence' => $currentEvidence,
                    'course_revision_changed' => $archiveContinuation !== null,
                    'course_id' => $courseId,
                    'current_lesson_id' => $currentLesson?->id,
                    'current_section_id' => $archiveContinuation['current_section']?->id ?? null,
                    'reward' => $reward,
                ],
            ]);
        }

        $acceptedSession = $sessionResult['session'] ?? null;
        $incomingSessionId = $acceptedSession?->id;
        $incomingSessionStartedAt = $acceptedSession?->started_at;
        $incomingSequence = isset($validated['sequence']) ? (int) $validated['sequence'] : null;
        $resumeRecorded = true;
        $resumeLesson = $currentLesson ?? $lesson;
        $courseName = (string) (
            ($archiveContinuation['canonical_course'] ?? $lesson->course)?->name_ar
            ?? ($archiveContinuation['canonical_course'] ?? $lesson->course)?->name_en
            ?? ''
        );

        $log = DB::transaction(function () use (
            $user,
            $resumeLesson,
            $courseId,
            $courseName,
            $position,
            $duration,
            $validated,
            $incomingSessionId,
            $incomingSessionStartedAt,
            $incomingSequence,
            $evidence,
            &$resumeRecorded
        ): WatchingLog {
            // The unique pair plus insert-or-ignore closes the first-watch race
            // without holding a coarse lock on every action from this user.
            DB::table('watching_logs')->insertOrIgnore([
                'user_id' => $user->id,
                'lesson_id' => $resumeLesson->id,
                'lesson_name' => (string) $resumeLesson->title,
                'course_id' => $courseId,
                'course_section_id' => $resumeLesson->courseSection?->id,
                'course_name' => $courseName,
                'position_seconds' => 0,
                'duration_seconds' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $log = WatchingLog::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $resumeLesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($incomingSessionId && $incomingSessionStartedAt && $log->playback_session_started_at) {
                $incomingKey = $incomingSessionStartedAt->format('Y-m-d H:i:s.u') . ':' . $incomingSessionId;
                $storedKey = $log->playback_session_started_at->format('Y-m-d H:i:s.u')
                    . ':' . (string) $log->playback_session_id;
                if (strcmp($incomingKey, $storedKey) < 0) {
                    $resumeRecorded = false;
                }
            } elseif (!$incomingSessionId && $log->playback_session_started_at) {
                // An unsequenced legacy retry cannot be ordered against a
                // server-issued session from another device. Keep the newer
                // authoritative resume pointer instead of letting it jump.
                $resumeRecorded = false;
            }

            if ($resumeRecorded) {
                $resumeAttributes = [
                    'lesson_name' => (string) $resumeLesson->title,
                    'course_id' => $courseId,
                    'course_section_id' => $resumeLesson->courseSection?->id,
                    'course_name' => $courseName,
                    'position_seconds' => $position,
                    'duration_seconds' => $duration ?? $log->duration_seconds,
                    'watched_at' => now(),
                ];
                if ($incomingSessionId && $incomingSessionStartedAt) {
                    $resumeAttributes += [
                        'playback_session_id' => $incomingSessionId,
                        'playback_session_started_at' => $incomingSessionStartedAt,
                        'last_playback_sequence' => $incomingSequence,
                    ];
                }
                $log->fill($resumeAttributes);
            }

            if (
                (bool) ($evidence['eligible_for_completion'] ?? false)
                && $log->completed_at === null
            ) {
                $log->completed_at = now();
            }

            $log->save();
            return $log;
        });

        // Rewards use the same server-qualified delta. Seeking to the end no
        // longer earns coins or manufactures completion evidence.
        $reward = $rewards->recordStudy($user, (int) $evidence['credited_seconds']);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حفظ موضع المشاهدة',
            'data' => [
                'recorded' => $resumeRecorded,
                'resume_ignored_as_stale_session' => !$resumeRecorded,
                'tracking_enabled' => true,
                'history_id' => $log->id,
                'course_id' => $courseId,
                'lesson_id' => $lesson->id,
                'course_section_id' => $log->course_section_id,
                'position_seconds' => $log->position_seconds,
                'duration_seconds' => $log->duration_seconds,
                'watched_at' => $log->watched_at,
                'learning_evidence' => $evidence,
                'current_learning_evidence' => $currentEvidence,
                'course_revision_changed' => $archiveContinuation !== null,
                'current_lesson_id' => $currentLesson?->id,
                'current_section_id' => $archiveContinuation['current_section']?->id ?? null,
                'reward' => $reward,
            ],
        ]);
    }

}
