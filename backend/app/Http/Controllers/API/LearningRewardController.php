<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\LearningRewardService;
use Illuminate\Http\JsonResponse;

final class LearningRewardController extends Controller
{
    public function configuration(
        LearningRewardService $rewards,
        ApiResponseService $responses
    ): JsonResponse {
        return $responses->success(
            $rewards->configuration(),
            'Learning economy configuration retrieved successfully'
        );
    }

    public function daily(
        LearningRewardService $rewards,
        ApiResponseService $responses
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();

        return $responses->success(
            $rewards->claimDaily($user),
            'Daily learning reward processed successfully'
        );
    }
}
