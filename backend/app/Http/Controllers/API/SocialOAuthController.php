<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\SocialOAuthAttempt;
use App\Services\SocialAuthProviderRegistry;
use App\Services\SocialOAuthAttemptService;
use App\Support\RoknLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class SocialOAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $socialProviders,
        private readonly SocialOAuthAttemptService $attempts
    ) {}

    public function start(Request $request, string $socialProvider): RedirectResponse
    {
        $provider = $this->provider($socialProvider);
        $pkce = $request->validate([
            'code_challenge' => ['nullable', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'code_challenge_method' => ['nullable', 'in:S256'],
        ]);
        $challenge = trim((string) ($pkce['code_challenge'] ?? ''));
        if ($challenge === '' && !config('social_auth.allow_legacy_pkce', false)) {
            abort(422, 'تعذّر بدء تسجيل الدخول');
        }
        if ($challenge !== '' && ($pkce['code_challenge_method'] ?? 'S256') !== 'S256') {
            abort(422, 'تعذّر بدء تسجيل الدخول');
        }
        $returnTo = (string) $request->query('return_to', 'rokn://auth');
        if (!in_array($returnTo, $this->allowedReturnUrls(), true)) {
            abort(422, 'تعذّر بدء تسجيل الدخول');
        }

        $state = Str::random(64);
        $attempt = $this->attempts->begin(
            $state,
            $provider,
            $returnTo,
            $challenge !== '' ? $challenge : null
        );

        try {
            return redirect()->away($this->authorizationUrl($provider, $state));
        } catch (\Throwable $exception) {
            $attempt->delete();
            report($exception);

            return $this->redirectToApp(
                $returnTo,
                $this->callbackPayload($attempt, ['error' => 'provider_unavailable'])
            );
        }
    }

    public function callback(Request $request, string $socialProvider): RedirectResponse
    {
        $provider = $this->provider($socialProvider);
        $state = (string) $request->query('state', '');
        // Inspect first so a callback sent to the wrong provider cannot burn a
        // legitimate state value. The atomic pull happens only after all
        // callback constraints match.
        $stateAttempt = $state !== '' ? $this->attempts->inspectState($state) : null;
        $returnTo = $stateAttempt
            ? (string) $stateAttempt->return_to
            : 'rokn://auth';

        if (!$stateAttempt || !hash_equals((string) $stateAttempt->provider, $provider)) {
            return $this->redirectToApp($returnTo, ['error' => 'login_cancelled']);
        }

        if (!$request->filled('code')) {
            // A valid provider cancellation is terminal for this browser
            // attempt. Consume its state so a copied callback cannot be reused.
            $this->attempts->consumeState($state, $provider);
            $providerError = strtolower(trim((string) $request->query('error', '')));

            return $this->redirectToApp($returnTo, $this->callbackPayload($stateAttempt, [
                'error' => in_array($providerError, ['access_denied', 'user_cancelled', 'cancelled'], true)
                    ? 'login_cancelled'
                    : 'provider_unavailable',
            ]));
        }

        $claimedState = $this->attempts->consumeState($state, $provider);
        if (!$claimedState) {
            return $this->redirectToApp($returnTo, ['error' => 'login_cancelled']);
        }

        try {
            $token = $this->exchangeCode($provider, (string) $request->query('code'));
            $completionCode = Str::random(72);
            // Provider credentials remain encrypted at rest. Database-backed
            // attempts survive container changes between callback and app
            // completion while the one-time hashes remain non-replayable.
            $this->attempts->issueCompletion(
                $claimedState,
                $completionCode,
                Crypt::encryptString($token)
            );

            return $this->redirectToApp(
                $returnTo,
                $this->callbackPayload($claimedState, ['code' => $completionCode])
            );
        } catch (\Throwable $exception) {
            report($exception);
            return $this->redirectToApp(
                $returnTo,
                $this->callbackPayload($claimedState, ['error' => 'provider_unavailable'])
            );
        }
    }

    public function complete(Request $request, SignController $signController)
    {
        $validated = $request->validate([
            'code' => 'required|string|min:32|max:200',
            'code_verifier' => ['nullable', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'device_os' => 'nullable|string|max:255',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_id' => ['nullable', 'uuid'],
        ]);

        // Inspect without consuming first. A wrong verifier must not be able to
        // burn the legitimate app's one-time completion code.
        $attempt = $this->attempts->inspectCompletion((string) $validated['code']);
        if (!$attempt) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }

        if (!$this->browserProviders()->contains((string) $attempt->provider)) {
            // Corrupted or injected attempt records are unusable and should not
            // remain replayable. Verifier mismatches on a valid provider stay
            // non-consuming so an attacker cannot burn the real app's code.
            $this->attempts->consumeCompletion((string) $validated['code']);

            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }

        $challenge = trim((string) $attempt->code_challenge);
        $verifier = trim((string) ($validated['code_verifier'] ?? ''));
        if ($challenge === '') {
            if (!config('social_auth.allow_legacy_pkce', false)) {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'social_login_pkce_required',
                    'message' => 'ابدأ تسجيل الدخول من جديد',
                    'data' => null,
                ], 410);
            }
        } elseif ($verifier === '' || !hash_equals($challenge, $this->pkceChallenge($verifier))) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'social_login_pkce_mismatch',
                'message' => "تعذر إكمال محاولة تسجيل الدخول\nابدأ من جديد",
                'data' => null,
            ], 422);
        }

        if ($attempt->completion_consumed_at) {
            return $this->replayCompletedSession($attempt);
        }

        if (empty($attempt->encrypted_token)) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }

        $claimedAttempt = $this->attempts->claimCompletion((string) $validated['code']);
        if (!$claimedAttempt) {
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'social_login_in_progress',
                'message' => "جارٍ إكمال تسجيل الدخول\nحاول بعد قليل",
                'data' => null,
            ], 409);
        }
        try {
            $providerToken = Crypt::decryptString((string) $claimedAttempt->encrypted_token);
        } catch (\Throwable $exception) {
            report($exception);
            $this->attempts->finalizeCompletion($claimedAttempt);

            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }

        $forward = Request::create('/api/v1/social-login', 'POST', [
            'provider' => $claimedAttempt->provider,
            'token' => $providerToken,
            'device_os' => $validated['device_os'] ?? null,
            'device_token' => $validated['device_token'] ?? null,
            'device_type' => $validated['device_type'] ?? null,
            'device_id' => $validated['device_id'] ?? null,
            'preferred_locale' => RoknLocale::fromRequest($request),
        ]);
        $forward->attributes->set('social_attempt_started_at', $claimedAttempt->created_at);

        try {
            $response = $signController->socialLogin($forward);
        } catch (\Throwable $exception) {
            $this->attempts->releaseCompletion($claimedAttempt);
            throw $exception;
        }

        if ($response->isSuccessful()) {
            try {
                $this->attempts->finalizeCompletion(
                    $claimedAttempt,
                    Crypt::encryptString(json_encode([
                        'status' => $response->status(),
                        'body' => $response->getData(true),
                    ], JSON_THROW_ON_ERROR))
                );
            } catch (\Throwable $exception) {
                // Identity verification and token issuance already succeeded.
                // A replay-snapshot write is recovery infrastructure; it must
                // not replace the live successful response with a 500 and make
                // the app report that it could not save the login.
                report($exception);
                try {
                    $this->attempts->releaseCompletion($claimedAttempt);
                } catch (\Throwable $releaseException) {
                    report($releaseException);
                }
            }
        } else {
            // Provider or database outages remain safely retryable with the
            // same one-time code. A short processing claim still prevents two
            // concurrent requests from creating parallel app sessions.
            $this->attempts->releaseCompletion($claimedAttempt);
        }

        return $response;
    }

    private function replayCompletedSession(\App\Models\SocialOAuthAttempt $attempt)
    {
        if (empty($attempt->encrypted_session_response)) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }

        try {
            $replay = json_decode(
                Crypt::decryptString((string) $attempt->encrypted_session_response),
                true,
                32,
                JSON_THROW_ON_ERROR
            );
            $body = is_array($replay['body'] ?? null) ? $replay['body'] : null;
            $status = (int) ($replay['status'] ?? 0);
            if ($body === null || $status < 200 || $status >= 300) {
                throw new RuntimeException('Invalid stored social session response.');
            }

            return response()->json($body, $status);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                'data' => null,
            ], 410);
        }
    }

    private function authorizationUrl(string $provider, string $state): string
    {
        $redirectUri = $this->callbackUrl($provider);
        if ($provider === 'google') {
            $clientId = (string) config('services.google.client_id');
            $this->requireValue($clientId, 'Google client ID');
            return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'prompt' => 'select_account',
                'state' => $state,
            ]);
        }

        if ($provider === 'facebook') {
            $clientId = (string) config('services.facebook.client_id');
            $this->requireValue($clientId, 'Facebook client ID');
            return 'https://www.facebook.com/' . $this->facebookGraphVersion() . '/dialog/oauth?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'email,public_profile',
                'state' => $state,
            ]);
        }

        $clientKey = (string) config('services.tiktok.client_key');
        $this->requireValue($clientKey, 'TikTok client key');
        return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
            'client_key' => $clientKey,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'user.info.basic',
            'state' => $state,
        ]);
    }

    private function exchangeCode(string $provider, string $code): string
    {
        $redirectUri = $this->callbackUrl($provider);
        if ($provider === 'google') {
            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ])->throw();
            return $this->token($response->json('id_token'));
        }

        if ($provider === 'facebook') {
            $response = Http::timeout($this->requestTimeout())->get(
                'https://graph.facebook.com/' . $this->facebookGraphVersion() . '/oauth/access_token', [
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ])->throw();
            return $this->token($response->json('access_token'));
        }

        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => config('services.tiktok.client_key'),
            'client_secret' => config('services.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ])->throw();
        return $this->token($response->json('access_token'));
    }

    private function redirectToApp(string $returnTo, array $query): RedirectResponse
    {
        $separator = str_contains($returnTo, '?') ? '&' : '?';
        return redirect()->away($returnTo . $separator . http_build_query($query));
    }

    /** Bind a mobile callback to the PKCE flow that created it. */
    private function callbackPayload(SocialOAuthAttempt $attempt, array $query): array
    {
        $binding = trim((string) $attempt->code_challenge);
        if ($binding !== '') {
            $query['attempt'] = $binding;
        }

        return $query;
    }

    private function callbackUrl(string $provider): string
    {
        $publicApiUrl = rtrim(trim((string) config('social_auth.public_api_url')), '/');
        if ($publicApiUrl !== '') {
            return $publicApiUrl . '/social-auth/' . rawurlencode($provider) . '/callback';
        }

        return route('api.social.callback', ['socialProvider' => $provider]);
    }

    private function allowedReturnUrls(): array
    {
        $urls = config('social_auth.return_urls', ['rokn://auth']);

        if (!is_array($urls)) {
            return ['rokn://auth'];
        }

        $safe = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $urls
        ), static fn (string $value): bool => $value === 'rokn://auth')));

        return $safe !== [] ? $safe : ['rokn://auth'];
    }

    private function facebookGraphVersion(): string
    {
        $version = trim((string) config('services.facebook.graph_version', ''));
        if (!preg_match('/^v\d+\.\d+$/', $version) || $version === 'v19.0') {
            throw new RuntimeException('Invalid Facebook Graph API version.');
        }

        return $version;
    }

    private function requestTimeout(): int
    {
        return max(3, (int) config('social_auth.timeout_seconds', 10));
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless($this->browserProviders()->contains($provider), 404);
        return $provider;
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function browserProviders(): \Illuminate\Support\Collection
    {
        return $this->socialProviders->declared()
            ->reject(static fn (string $provider): bool => $provider === 'apple')
            ->values();
    }

    private function token(mixed $value): string
    {
        $token = is_string($value) ? trim($value) : '';
        if ($token === '') {
            throw new RuntimeException('The provider did not return an identity token.');
        }
        return $token;
    }

    private function requireValue(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new RuntimeException($name . ' is not configured.');
        }
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
