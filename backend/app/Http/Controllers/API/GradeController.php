<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeRequest;
use App\Http\Resources\GradeResource;
use App\Models\Grade;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GradeController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $grades = Grade::active()
                ->ordered()
                ->withCount('courses')
                ->get();

            return $this->responses->success(
                GradeResource::collection($grades),
                'Grades retrieved successfully'
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to fetch grades',
                500,
                null,
                ['error' => 'Failed to fetch grades']
            );
        }
    }

    public function store(GradeRequest $request): JsonResponse
    {
        try {
            $grade = Grade::create($request->validated());

            return $this->responses->success(
                new GradeResource($grade),
                'Grade created successfully',
                201
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to create grade',
                500,
                null,
                ['error' => 'Failed to create grade']
            );
        }
    }

    public function show(Grade $grade): JsonResponse
    {
        try {
            return $this->responses->success(
                new GradeResource($grade->load('courses')),
                'Grade retrieved successfully'
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to fetch grade',
                500,
                null,
                ['error' => 'Failed to fetch grade']
            );
        }
    }

    public function update(GradeRequest $request, Grade $grade): JsonResponse
    {
        try {
            $grade->update($request->validated());

            return $this->responses->success(
                new GradeResource($grade),
                'Grade updated successfully'
            );
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to update grade',
                500,
                null,
                ['error' => 'Failed to update grade']
            );
        }
    }

    public function destroy(Grade $grade): JsonResponse
    {
        try {
            $grade->delete();

            return $this->responses->success(null, 'Grade deleted successfully');
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to delete grade',
                500,
                null,
                ['error' => 'Failed to delete grade']
            );
        }
    }

    public function courses(Grade $grade): JsonResponse
    {
        try {
            $courses = $grade->courses()->with(['category', 'grade'])->get();

            return $this->responses->success([
                'grade' => new GradeResource($grade),
                'courses' => $courses,
            ], 'Grade courses retrieved successfully');
        } catch (\Exception $exception) {
            report($exception);

            return $this->responses->error(
                'Failed to fetch grade courses',
                500,
                null,
                ['error' => 'Failed to fetch grade courses']
            );
        }
    }
}
