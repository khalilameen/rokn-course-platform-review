<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNotificationsResource;
use App\Models\AdminNotification;
use App\Services\ApiResponseService;
use App\Services\EngagementMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminNotificationsController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function index(): JsonResource
    {
        return $this->responses->resource(
            AdminNotificationsResource::collection(
                AdminNotification::query()->available()->orderBy('priority')->get()
            ),
            'Admin notifications retrieved successfully'
        );
    }

    public function message(
        string $systemKey,
        EngagementMessageService $messages
    ): JsonResponse {
        abort_unless(in_array($systemKey, [
            'guest_registration_prompt',
            'welcome_bonus_received',
        ], true), 404);

        $message = $messages->publicMessage($systemKey);
        if (!$message) {
            return $this->responses->success(null, 'Engagement message is disabled');
        }

        return $this->responses->success($message, 'Engagement message retrieved successfully');
    }
}
