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
        $validated = $request->validate([
            'bucket' => ['sometimes', 'integer', 'min:0', 'max:99'],
        ]);
        $snapshot = $features->clientSnapshot((int) ($validated['bucket'] ?? 0));

        return $responses
            ->success($snapshot, 'Product features retrieved successfully')
            ->header('Cache-Control', 'public, max-age=60, stale-if-error=300');
    }
}
