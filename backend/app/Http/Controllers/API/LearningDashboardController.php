<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\LearningDashboardService;
use Illuminate\Http\JsonResponse;

final class LearningDashboardController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly LearningDashboardService $dashboard
    ) {
    }

    public function courses(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->dashboard->forUser($user),
            'تم تحميل كورساتك'
        );
    }
}
