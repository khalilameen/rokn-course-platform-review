<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\CourseSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseSearchController extends Controller
{
    public function __invoke(
        Request $request,
        CourseSearchService $search,
        ApiResponseService $responses
    ): JsonResponse {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:20',
            'classification_id' => 'nullable|integer|exists:classifications,id',
            'course_type' => 'nullable|string|max:50',
        ]);

        return $responses->success(
            $search->results($validated),
            'Course search completed successfully'
        );
    }
}
