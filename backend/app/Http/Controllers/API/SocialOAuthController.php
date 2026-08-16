<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class SocialOAuthController extends Controller
{
    private const PROVIDERS = ['google', 'facebook', 'tiktok'];

    public function start(Request $request, string $socialProvider): RedirectResponse
    {
        $provider = $this->provider($socialProvider);
        $pkce = $request->validate([
            'code_challenge' => ['nullable', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'],
            'code_challenge_method' => ['nullable', 'in:S256'],
        ]);
        $challenge = trim((string) ($pkce['code_challenge'] ?? ''));
        if ($challenge === '' && !config('social_auth.allow_legacy_pkce', false)) {
            abort(422, 'PKCE code challenge is required.');
        }
        if ($challenge !== '' && ($pkce['code_challenge_method'] ?? 'S256') !== 'S256') {
            abort(422, 'Only PKCE S256 is supported.');
        }
        $returnTo = (string) $request->query('return_to', 'rokn://auth');
        if (!in_array($returnTo, $this->allowedReturnUrls(), true)) {
            abort(422, 'Invalid return URL.');
        }

        $state = Str::random(64);
        Cache::put($this->stateKey($state), [
            'provider' => $provider,
            'return_to' => $returnTo,
            'code_challenge' => $challenge !== '' ? $challenge : null,
        ], now()->addMinutes(10));

        try {
            return redirect()->away($this->authorizationUrl($provider, $state));
        } catch (\Throwable $exception) {
            Cache::forget($this->stateKey($state));
            report($exception);

            return $this->redirectToApp($returnTo, ['error' => 'provider_unavailable']);
        }
    }

    public function callback(Request $request, string $socialProvider): RedirectResponse
    {
        $provider = $this->provider($socialProvider);
        $state = (string) $request->query('state', '');
        // Inspect first so a callback sent to the wrong provider cannot burn a
        // legitimate state value. The atomic pull happens only after all
        // callback constraints match.
        $statePayload = $state !== '' ? Cache::get($this->stateKey($state)) : null;
        $returnTo = is_array($statePayload)
            ? (string) ($statePayload['return_to'] ?? 'rokn://auth')
            : 'rokn://auth';

        if (
            !is_array($statePayload) ||
            !hash_equals((string) ($statePayload['provider'] ?? ''), $provider) ||
            !$request->filled('code')
        ) {
            return $this->redirectToApp($returnTo, ['error' => 'login_cancelled']);
        }

        $claimedState = Cache::pull($this->stateKey($state));
        if (!is_array($claimedState)) {
            return $this->redirectToApp($returnTo, ['error' => 'login_cancelled']);
        }

        try {
            $token = $this->exchangeCode($provider, (string) $request->query('code'));
            $completionCode = Str::random(72);
            Cache::put($this->completionKey($completionCode), [
                'provider' => $provider,
                // Provider credentials never sit in cache as readable text.
                'encrypted_token' => Crypt::encryptString($token),
                'code_challenge' => $claimedState['code_challenge'] ?? null,
            ], now()->addMinutes(3));

            return $this->redirectToApp($returnTo, ['code' => $completionCode]);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->redirectToApp($returnTo, ['error' => 'provider_unavailable']);
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
        ]);

        $completionKey = $this->completionKey((string) $validated['code']);
        // Inspect without consuming first. A wrong verifier must not be able to
        // burn the legitimate app's one-time completion code.
        $payload = Cache::get($completionKey);
        if (!is_array($payload) || empty($payload['encrypted_token'])) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => 'انتهت محاولة تسجيل الدخول. ابدأ مرة أخرى.',
            ], 410);
        }

        if (!in_array((string) ($payload['provider'] ?? ''), self::PROVIDERS, true)) {
            // Corrupted or injected cache records are unusable and should not
            // remain replayable. Verifier mismatches on a valid provider stay
            // non-consuming so an attacker cannot burn the real app's code.
            Cache::pull($completionKey);

            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => 'انتهت محاولة تسجيل الدخول. ابدأ مرة أخرى.',
            ], 410);
        }

        $challenge = trim((string) ($payload['code_challenge'] ?? ''));
        $verifier = trim((string) ($validated['code_verifier'] ?? ''));
        if ($challenge === '') {
            if (!config('social_auth.allow_legacy_pkce', false)) {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'social_login_pkce_required',
                    'message' => 'ابدأ تسجيل الدخول من جديد لتأمين هذه المحاولة.',
                ], 410);
            }
        } elseif ($verifier === '' || !hash_equals($challenge, $this->pkceChallenge($verifier))) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'social_login_pkce_mismatch',
                'message' => 'تعذر تأمين محاولة تسجيل الدخول. ابدأ من جديد.',
            ], 422);
        }

        $claimedPayload = Cache::pull($completionKey);
        if (!is_array($claimedPayload)) {
            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => 'انتهت محاولة تسجيل الدخول. ابدأ مرة أخرى.',
            ], 410);
        }
        $payload = $claimedPayload;

        try {
            $providerToken = Crypt::decryptString((string) $payload['encrypted_token']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 410,
                'success' => false,
                'code' => 'social_login_expired',
                'message' => 'انتهت محاولة تسجيل الدخول. ابدأ مرة أخرى.',
            ], 410);
        }

        $forward = Request::create('/api/v1/social-login', 'POST', [
            'provider' => $payload['provider'],
            'token' => $providerToken,
            'device_os' => $validated['device_os'] ?? null,
            'device_token' => $validated['device_token'] ?? null,
            'device_type' => $validated['device_type'] ?? null,
        ]);

        return $signController->socialLogin($forward);
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
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        return $provider;
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

    private function stateKey(string $state): string
    {
        return 'social-oauth-state:' . hash('sha256', $state);
    }

    private function completionKey(string $code): string
    {
        return 'social-oauth-complete:' . hash('sha256', $code);
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
