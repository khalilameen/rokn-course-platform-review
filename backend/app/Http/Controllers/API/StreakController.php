<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;

final class StreakController extends Controller
{
    /**
     * Get streak data for the authenticated user.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        $data = StreakService::getStreakDataForUser($user->id);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Learning streak retrieved successfully',
            'data' => $data,
        ]);
    }
}
