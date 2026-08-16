<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

final readonly class ApiResponseService
{
    public function success(
        mixed $data,
        string $message,
        int $httpStatus = 200,
        array $additional = []
    ): JsonResponse {
        return response()->json([
            'status' => $httpStatus,
            'success' => true,
            'data' => $data,
            'message' => $message,
        ] + $additional, $httpStatus);
    }

    public function error(
        string $message,
        int $httpStatus,
        mixed $data = null,
        array $additional = []
    ): JsonResponse {
        return response()->json([
            'status' => $httpStatus,
            'success' => false,
            'data' => $data,
            'message' => $message,
        ] + $additional, $httpStatus);
    }

    public function resource(
        JsonResource $resource,
        string $message,
        int $httpStatus = 200
    ): JsonResource {
        return $resource->additional([
            'status' => $httpStatus,
            'success' => true,
            'message' => $message,
        ]);
    }
}
