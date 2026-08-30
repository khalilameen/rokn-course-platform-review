<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ApiResponseService;
use App\Services\PublicPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicPortfolioController extends Controller
{
    public function show(
        Request $request,
        string $slug,
        PublicPortfolioService $service,
        ApiResponseService $responses
    ): JsonResponse {
        $highlight = $request->query('certificate');
        $portfolio = $service->find($slug, is_string($highlight) ? $highlight : null);
        if (!$portfolio) {
            return $responses->error('Portfolio not found', 404);
        }

        return $responses
            ->success($portfolio, 'Portfolio retrieved successfully')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
