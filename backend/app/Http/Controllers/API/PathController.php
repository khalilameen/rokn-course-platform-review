<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PathResource;
use App\Models\Level;
use App\Models\Path;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseDurationService;
use App\Services\CourseCatalogueQueryService;
use App\Services\UserPathProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

final class PathController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseDurationService $duration,
        private readonly CourseCatalogueQueryService $catalogue,
        private readonly UserPathProgressService $progress
    ) {
    }

    public function index(): JsonResource
    {
        $levels = Level::ordered()->get();
        $paths = Path::query()
            ->whereHas('courses', fn ($courses) => $this->catalogue->constrainPublic($courses))
            ->with([
                'interests',
                'courses' => function ($courses): void {
                    $this->catalogue->orderForDiscovery(
                        $this->catalogue->applyPublicContract($courses->getQuery())
                    )->with('level');
                },
            ])
            ->orderBy('id')
            ->get();
        $paths->each->setRelation('availableLevels', $levels);
        $this->duration->attachMany(
            $paths->flatMap(fn (Path $path) => $path->courses)->unique('id')->values()
        );

        return $this->responses->resource(
            PathResource::collection($paths),
            'تم تحميل المسارات'
        );
    }

    public function show(int|string $id): JsonResource
    {
        $path = Path::query()
            ->whereHas('courses', fn ($courses) => $this->catalogue->constrainPublic($courses))
            ->with([
                'interests',
                'courses' => function ($courses): void {
                    $this->catalogue->orderForDiscovery(
                        $this->catalogue->applyPublicContract($courses->getQuery())
                    )->with('level');
                },
            ])
            ->findOrFail($id);
        $path->setRelation('availableLevels', Level::ordered()->get());
        $this->duration->attachMany($path->courses->unique('id')->values());

        return $this->responses->resource(
            new PathResource($path),
            'تم تحميل المسار'
        );
    }

    public function userPaths(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->progress->forUser($user),
            'تم تحميل تقدمك في المسارات'
        );
    }
}
