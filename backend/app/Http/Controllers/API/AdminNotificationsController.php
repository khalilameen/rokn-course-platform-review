<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminNotificationsResource;
use App\Models\AdminNotification;
use App\Services\ApiResponseService;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminNotificationsController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function index(): JsonResource
    {
        return $this->responses->resource(
            AdminNotificationsResource::collection(AdminNotification::all()),
            'Admin notifications retrieved successfully'
        );
    }
}
