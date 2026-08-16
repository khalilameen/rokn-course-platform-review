<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

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
                'Playback manifest issued successfully'
            );
        } catch (AuthorizationException $exception) {
            return $responses->error($exception->getMessage(), 403);
        } catch (RuntimeException $exception) {
            return $responses->error($exception->getMessage(), 409);
        }
    }
}
