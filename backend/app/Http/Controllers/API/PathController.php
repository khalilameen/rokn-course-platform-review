<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PathResource;
use App\Models\Path;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\UserPathProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

final class PathController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly UserPathProgressService $progress
    ) {
    }

    public function index(): JsonResource
    {
        $paths = Path::with(['interests', 'courses.level'])->get();

        return $this->responses->resource(
            PathResource::collection($paths),
            'Paths retrieved successfully'
        );
    }

    public function show(int|string $id): JsonResource
    {
        $path = Path::with([
            'interests',
            'courses.level',
            'courses.classifications',
            'courses.teachers',
        ])->findOrFail($id);

        return $this->responses->resource(
            new PathResource($path),
            'Path retrieved successfully'
        );
    }

    public function userPaths(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->progress->forUser($user),
            'User paths retrieved successfully'
        );
    }
}
