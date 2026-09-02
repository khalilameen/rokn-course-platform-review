<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\CourseCatalogueQueryService;
use App\Services\CourseSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseSearchController extends Controller
{
    public function __invoke(
        Request $request,
        CourseSearchService $search,
        CourseCatalogueQueryService $catalogue,
        ApiResponseService $responses
    ): JsonResponse {
        $validated = $request->validate([
            'q' => 'required|string|min:1|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:20',
            // This is a read filter, not a foreign key mutation. A learner can
            // keep an old classification selected while an editor removes or
            // merges it. Let the public catalogue boundary return an empty
            // page instead of turning that harmless stale UI state into a 422
            // which the app presents as a broken search journey.
            'classification_id' => 'nullable|integer|min:1',
            'course_type' => 'nullable|string|max:50',
            'catalogue_revision' => 'nullable|integer|min:1',
        ]);

        $revision = $catalogue->revision();
        $page = max(1, (int) ($validated['page'] ?? 1));
        $expectedRevision = isset($validated['catalogue_revision'])
            ? (int) $validated['catalogue_revision']
            : null;
        if ($page > 1 && $expectedRevision !== null && $expectedRevision !== $revision) {
            return $responses->error(
                "تغيّرت نتائج البحث\nنحدّثها الآن",
                409,
                ['catalogue_revision' => $revision],
                ['code' => 'catalogue_changed']
            );
        }

        $results = $search->results($validated);
        $finalRevision = $catalogue->revision();
        if ($finalRevision !== $revision) {
            return $responses->error(
                "تغيّرت نتائج البحث\nنحدّثها الآن",
                409,
                ['catalogue_revision' => $finalRevision],
                ['code' => 'catalogue_changed']
            );
        }
        $results['catalogue_revision'] = $revision;

        return $responses->success(
            $results,
            'تم تحميل نتائج البحث'
        );
    }
}
