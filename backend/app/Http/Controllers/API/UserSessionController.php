<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var ApiToken|null $current */
        $current = $request->attributes->get('rokn_api_token');
        $sessions = $request->user()->apiTokens()
            ->whereHasNotExpired()
            ->whereNotNull('session_id')
            ->orderByDesc('last_used_at')
            ->orderByDesc('issued_at')
            ->limit(25)
            ->get()
            ->map(static fn (ApiToken $token): array => [
                'id' => $token->session_id,
                'platform' => $token->platform ?: 'other',
                'app_version' => $token->app_version,
                'app_build' => $token->app_build,
                'issued_at' => optional($token->issued_at)->toIso8601String(),
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                'expires_at' => optional($token->expired_at)->toIso8601String(),
                'current' => $current !== null && hash_equals((string) $current->session_id, (string) $token->session_id),
            ])
            ->values();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Sessions retrieved successfully',
            'data' => $sessions,
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        /** @var ApiToken|null $current */
        $current = $request->attributes->get('rokn_api_token');
        /** @var ApiToken $session */
        $session = $request->user()->apiTokens()
            ->where('session_id', $sessionId)
            ->whereHasNotExpired()
            ->firstOrFail();

        $isCurrent = $current !== null
            && hash_equals((string) $current->session_id, (string) $session->session_id);
        $session->revoke();

        $message = $isCurrent ? 'تم تسجيل الخروج من هذا الجهاز' : 'تم إنهاء الجلسة';

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $message,
            'data' => ['signed_out' => $isCurrent],
            'signed_out' => $isCurrent,
        ]);
    }
}
