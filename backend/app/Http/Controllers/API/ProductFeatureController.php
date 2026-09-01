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
        $installation = strtolower(trim((string) $request->header('X-Rokn-Installation')));
        $subject = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $installation
        ) === 1
            ? 'installation:'.$installation
            : 'anonymous:'.hash('sha256', (string) $request->ip().'|'.(string) $request->userAgent());
        $snapshot = $features->clientSnapshot($subject);

        return $responses
            ->success($snapshot, 'تم تحميل حالة المزايا')
            ->header('Cache-Control', 'private, max-age=60, stale-if-error=300')
            ->header('Vary', 'X-Rokn-Installation');
    }
}
