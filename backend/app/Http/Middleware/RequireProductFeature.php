<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProductFeatureFlagService;
use App\Services\RecoveryEvidenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireProductFeature
{
    public function __construct(
        private ProductFeatureFlagService $features,
        private RecoveryEvidenceService $recoveryEvidence
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $recoveryRequired = config('app.env') === 'production'
            || (bool) config('operations.disaster_recovery_mode', false);
        if (
            $feature === 'checkout'
            && $recoveryRequired
            && !$this->recoveryEvidence->readiness()['purchases_allowed']
        ) {
            return response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'recovery_verification_required',
                'feature' => $feature,
                'message' => "الدفع غير متاح الآن\nيمكنك متابعة محتواك الحالي",
            ], 503, ['Retry-After' => '300']);
        }

        $subject = auth('api')->id() ?? $request->ip();
        if (!$this->features->enabled($feature, $subject)) {
            return response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'feature_temporarily_unavailable',
                'feature' => $feature,
                'message' => 'This feature is temporarily unavailable.',
            ], 503);
        }

        return $next($request);
    }
}
