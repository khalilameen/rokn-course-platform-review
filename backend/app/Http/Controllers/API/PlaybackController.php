<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseRevisionChangedException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\ApiResponseService;
use App\Services\PlaybackCapabilityService;
use App\Services\PlaybackManifestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class PlaybackController extends Controller
{
    public function manifest(
        Request $request,
        Lesson $lesson,
        PlaybackManifestService $manifests,
        ApiResponseService $responses
    ): JsonResponse {
        $request->validate([
            'client' => 'nullable|string|max:24',
            'capability_version' => 'nullable|integer|min:1|max:10',
            'playback_session_id' => 'nullable|uuid',
        ] + PlaybackCapabilityService::validationRules());

        try {
            return $responses->success(
                $manifests->issue(auth('api')->user(), $lesson, $request->only([
                    'client',
                    'capability_version',
                    'playback_session_id',
                    'client_capabilities',
                ])),
                'الفيديو جاهز للتشغيل'
            );
        } catch (CourseRevisionChangedException $exception) {
            return $responses->error(
                "تم تحديث الكورس\nنعيد تحميل أحدث نسخة",
                409,
                $exception->contract(),
                ['code' => 'course_revision_changed']
            );
        } catch (AuthorizationException $exception) {
            $progressLocked = str_contains(
                strtolower($exception->getMessage()),
                'not available yet'
            );
            return $responses->error(
                $progressLocked
                    ? 'أكمل المحتوى السابق لفتح هذا المقطع'
                    : 'هذا المقطع غير متاح لحسابك',
                403,
                null,
                ['code' => $progressLocked ? 'lesson_locked' : 'lesson_unavailable']
            );
        } catch (RuntimeException $exception) {
            report($exception);
            $processing = str_contains(strtolower($exception->getMessage()), 'prepared')
                || str_contains(strtolower($exception->getMessage()), 'ready')
                || str_contains(strtolower($exception->getMessage()), 'changing');

            return $responses->error(
                $processing
                    ? "الفيديو قيد التجهيز\nحاول بعد قليل"
                    : "تعذر تشغيل الفيديو الآن\nحاول مرة أخرى",
                409,
                null,
                ['code' => $processing ? 'video_processing' : 'video_unavailable']
            );
        }
    }
}
