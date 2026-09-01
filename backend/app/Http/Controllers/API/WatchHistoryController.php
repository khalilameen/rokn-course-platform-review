<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\WatchingLog;
use App\Services\CourseChatAccessService;
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
        private readonly CourseChatAccessService $courseAccess
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
                    $duration = $log->duration_seconds;
                    $progress = $duration && $duration > 0
                        ? min(100, round(($log->position_seconds / $duration) * 100, 2))
                        : null;

                    return [
                        'id' => $log->id,
                        'course_id' => $log->course_id,
                        'course_title' => $log->course?->name_ar ?? $log->course_name,
                        'course_title_en' => $log->course?->name_en,
                        'course_image' => $log->course?->image,
                        'lesson_id' => $log->lesson_id,
                        'lesson_title' => $log->lesson?->title ?? $log->lesson_name,
                        'lesson_thumbnail' => $log->lesson?->thumbnail_path,
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

        if (
            !$course
            || !$section
            || !$course->isPublishedForLearning()
            || (int) $section->course_id !== $courseId
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

        if (
            (!$lesson->is_opened || $course->isNestedCourse())
            && !$this->courseAccess->hasLearningAccess((int) $user->id, $courseId)
        ) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'افتح الكورس أولًا لحفظ تقدمك',
                'data' => null,
            ], 403);
        }

        $duration = $validated['duration_seconds'] ?? null;
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
        $evidence = $this->learningEvidence->recordHeartbeat(
            $user,
            $lesson,
            $position,
            $duration,
            $sessionResult['previous_sample'] ?? null
        );

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
                    'reward' => $reward,
                ],
            ]);
        }

        $acceptedSession = $sessionResult['session'] ?? null;
        $incomingSessionId = $acceptedSession?->id;
        $incomingSessionStartedAt = $acceptedSession?->started_at;
        $incomingSequence = isset($validated['sequence']) ? (int) $validated['sequence'] : null;
        $resumeRecorded = true;
        $courseName = (string) ($lesson->course?->name_ar ?? $lesson->course?->name_en ?? '');

        $log = DB::transaction(function () use (
            $user,
            $lesson,
            $courseId,
            $courseName,
            $position,
            $duration,
            $validated,
            $incomingSessionId,
            $incomingSessionStartedAt,
            $incomingSequence,
            &$resumeRecorded
        ): WatchingLog {
            // The unique pair plus insert-or-ignore closes the first-watch race
            // without holding a coarse lock on every action from this user.
            DB::table('watching_logs')->insertOrIgnore([
                'user_id' => $user->id,
                'lesson_id' => $lesson->id,
                'lesson_name' => (string) $lesson->title,
                'course_id' => $courseId,
                'course_section_id' => $lesson->courseSection?->id,
                'course_name' => $courseName,
                'position_seconds' => 0,
                'duration_seconds' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $log = WatchingLog::query()
                ->where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($incomingSessionId && $incomingSessionStartedAt && $log->playback_session_started_at) {
                $incomingKey = $incomingSessionStartedAt->format('Y-m-d H:i:s.u') . ':' . $incomingSessionId;
                $storedKey = $log->playback_session_started_at->format('Y-m-d H:i:s.u')
                    . ':' . (string) $log->playback_session_id;
                if (strcmp($incomingKey, $storedKey) < 0) {
                    $resumeRecorded = false;
                }
            }

            if ($resumeRecorded) {
                $resumeAttributes = [
                    'lesson_name' => (string) $lesson->title,
                    'course_id' => $courseId,
                    'course_section_id' => $lesson->courseSection?->id,
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

            if (($validated['is_completed'] ?? false) && $log->completed_at === null) {
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
                'reward' => $reward,
            ],
        ]);
    }

}
