<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\ProductFeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductFeatureController extends Controller
{
    public function index(
        Request $request,
        ProductFeatureFlagService $features,
        ApiResponseService $responses
    ): JsonResponse {
        $snapshot = $features->clientSnapshot($features->subjectForRequest($request));

        return $responses
            ->success($snapshot, 'تم تحميل حالة المزايا')
            ->header('Cache-Control', 'private, max-age=60, stale-if-error=300')
            ->header('Vary', 'X-Rokn-Installation');
    }
}
