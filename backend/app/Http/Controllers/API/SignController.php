<?php

namespace App\Http\Controllers\API;

use App\Exceptions\SocialProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentProfileResource;
use App\Models\SocialAccount;
use App\Models\Setting;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\RewardRule;
use App\Services\FacebookService;
use App\Services\GoogleService;
use App\Services\TikTokService;
use App\Services\AppleService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\DeviceLoginService;
use App\Services\PortfolioShareIdentityService;
use App\Services\SocialIdentityGuardService;
use App\Support\RoknLocale;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SignController extends Controller
{
    private $facebookService;
    private $googleService;
    private $tikTokService;
    private $appleService;
    private SocialAuthProviderRegistry $socialProviders;
    private DeviceLoginService $deviceLogin;
    private PortfolioShareIdentityService $portfolioShares;
    private SocialIdentityGuardService $identityGuards;

    public function __construct(
        FacebookService $facebookService,
        GoogleService $googleService,
        TikTokService $tikTokService,
        AppleService $appleService,
        SocialAuthProviderRegistry $socialProviders,
        DeviceLoginService $deviceLogin,
        PortfolioShareIdentityService $portfolioShares,
        SocialIdentityGuardService $identityGuards
    ) {
        $this->facebookService = $facebookService;
        $this->googleService = $googleService;
        $this->tikTokService = $tikTokService;
        $this->appleService = $appleService;
        $this->socialProviders = $socialProviders;
        $this->deviceLogin = $deviceLogin;
        $this->portfolioShares = $portfolioShares;
        $this->identityGuards = $identityGuards;
    }

    /**
     * Social Login with provider-side token verification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function socialLogin(Request $request)
    {
        $attemptStartedAt = $request->attributes->get('social_attempt_started_at');
        $nonceRules = $request->input('provider') === 'apple'
            ? ['bail', 'required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/']
            : ['nullable', 'string', 'max:255'];

        $validated = $request->validate([
            'provider' => [
                'required',
                'string',
                Rule::in($this->socialProviders->declared()->all()),
            ],
            'token' => 'required|string|max:10000',
            'provider_name' => 'nullable|string|max:255',
            'nonce' => $nonceRules,
            'device_os' => 'nullable|string|max:255',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_id' => ['nullable', 'uuid'],
            'preferred_locale' => 'nullable|string|in:ar,en',
        ]);

        $provider = $validated['provider'];
        $token = $validated['token'];
        $localeInput = $validated['preferred_locale']
            ?? ($request->hasHeader('Accept-Language') ? $request->header('Accept-Language') : null);
        $preferredLocale = $localeInput === null
            ? null
            : (RoknLocale::normalize($localeInput) ?? RoknLocale::fromRequest($request));

        try {
            // Verify token with appropriate service
            $socialData = match($provider) {
                'facebook' => $this->facebookService->verify($token),
                'google' => $this->googleService->verify(
                    $token,
                    $request->attributes->get('social_expected_nonce_hash')
                ),
                'tiktok' => $this->tikTokService->verify($token),
                'apple' => $this->appleService->verify($token, (string) $validated['nonce']),
                default => throw new Exception('Unsupported provider'),
            };
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Social identity verification failed', [
                'provider' => $provider,
                'exception' => get_class($e),
            ]);

            $providerUnavailable = $this->isTransientSocialProviderFailure($e);

            return response()->json([
                'status' => $providerUnavailable ? 503 : 422,
                'success' => false,
                'code' => $providerUnavailable
                    ? 'social_provider_unavailable'
                    : 'social_identity_verification_failed',
                'message' => $providerUnavailable
                    ? "خدمة تسجيل الدخول غير متاحة للحظات\nحاول مرة أخرى"
                    : "تعذّر التحقق من الحساب\nابدأ تسجيل الدخول مرة أخرى",
                'data' => null,
            ], $providerUnavailable ? 503 : 422);
        }

        $providerId = trim((string) ($socialData['id'] ?? ''));
        if ($providerId === '' || strlen($providerId) > 191) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'social_identity_verification_failed',
                'message' => 'تعذّر التحقق من هوية الحساب',
                'data' => null,
            ], 422);
        }

        if (!$attemptStartedAt instanceof CarbonInterface) {
            $issuedAt = $socialData['identity_issued_at'] ?? null;
            if (!is_numeric($issuedAt) || (int) $issuedAt <= 0) {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'social_login_fresh_attempt_required',
                    'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                    'data' => null,
                ], 410);
            }
            $attemptStartedAt = CarbonImmutable::createFromTimestampUTC((int) $issuedAt);
            if ($attemptStartedAt->isAfter(now()->addMinutes(5))) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'code' => 'social_identity_verification_failed',
                    'message' => 'تعذّر التحقق من هوية الحساب',
                    'data' => null,
                ], 422);
            }
        }

        $email = isset($socialData['email']) && filter_var($socialData['email'], FILTER_VALIDATE_EMAIL)
            ? Str::lower((string) $socialData['email'])
            : null;
        $emailIsVerified = $email !== null && (bool) ($socialData['email_verified'] ?? false);
        $name = trim((string) ($socialData['name'] ?? '')) ?: 'طالب ركن';
        if (
            $provider === 'apple'
            && trim((string) ($socialData['name'] ?? '')) === ''
            && trim((string) ($validated['provider_name'] ?? '')) !== ''
        ) {
            // Apple sends fullName separately only on the first consent. It may
            // label the account; identity/email still come only from signed JWT.
            $name = trim((string) $validated['provider_name']);
        }
        $name = Str::limit($name, 255, '');
        $picture = isset($socialData['picture']) ? (string) $socialData['picture'] : null;

        try {
            [$user, $isNewUser] = DB::transaction(function () use (
                $provider,
                $providerId,
                $email,
                $emailIsVerified,
                $name,
                $picture,
                $attemptStartedAt,
                $preferredLocale
            ): array {
                $this->identityGuards->assertLoginStartedAfterLastDeletion(
                    $provider,
                    $providerId,
                    $attemptStartedAt
                );
                $socialAccount = SocialAccount::query()
                    ->where('provider', $provider)
                    ->where('provider_user_id', $providerId)
                    ->lockForUpdate()
                    ->first();

                $user = $socialAccount?->user;

                // Seamlessly migrate identities created before the social_accounts table existed.
                if (!$user) {
                    $user = User::query()
                        ->where('social_provider', $provider)
                        ->where('social_id', $providerId)
                        ->lockForUpdate()
                        ->first();
                }

                // Linking by email is allowed only when the provider itself verified that email.
                if (!$user && $emailIsVerified) {
                    $emailOwner = User::query()
                        ->where('email', $email)
                        ->lockForUpdate()
                        ->first();
                    if ($emailOwner && !$emailOwner->email_verified_at) {
                        // A learner may type any available address while
                        // editing the profile. That unverified string is not
                        // proof that a later provider identity owns this row.
                        throw new \DomainException('social_account_email_unverified');
                    }
                    $user = $emailOwner;
                }

                $isNewUser = false;
                if (!$user) {
                    $isNewUser = true;
                    $internalEmail = $emailIsVerified
                        ? $email
                        : sprintf('%s-%s@accounts.rokn.app', $provider, hash('sha256', $providerId));

                    $user = User::create([
                        'name' => $name,
                        'email' => $internalEmail,
                        'password' => Hash::make(Str::random(48)),
                        'social_provider' => $provider,
                        'social_id' => $providerId,
                        'profile_image' => $picture,
                        // Push is opt-in on the device. The inbox and welcome
                        // credit still work before a learner accepts the prompt.
                        'notifications_status' => false,
                        // Continuing from the social sign-in screen accepts the
                        // linked terms and privacy notice shown directly below it.
                        'terms_accepted_at' => now(),
                        'privacy_notice_acknowledged_at' => now(),
                        'legal_notice_version' => (string) config('social_auth.legal_notice_version', '2026-08-06'),
                    ]);
                    $user->forceFill([
                        'email_verified_at' => $emailIsVerified ? now() : null,
                        'role' => 'client',
                        'active' => true,
                    ])->save();

                    if (empty($user->portfolio_slug)) {
                        $this->portfolioShares->ensure($user);
                    }
                }

                $conflictingAccount = SocialAccount::query()
                    ->where('user_id', $user->id)
                    ->where('provider', $provider)
                    ->where('provider_user_id', '!=', $providerId)
                    ->exists();
                if ($conflictingAccount) {
                    throw new \DomainException('social_account_conflict');
                }

                SocialAccount::updateOrCreate(
                    ['provider' => $provider, 'provider_user_id' => $providerId],
                    [
                        'user_id' => $user->id,
                        'provider_email' => $email,
                        'provider_name' => $name,
                        'avatar_url' => $picture,
                        'last_verified_at' => now(),
                    ]
                );

                $updates = [];
                // Repair only empty/demo identity fields. Linking a provider to
                // an existing account must never overwrite a name the learner
                // has already chosen.
                $staleNames = ['طالب ركن', 'محمد السكماني', 'حساب المراجعة'];
                $rawName = trim((string) $user->getRawOriginal('name'));
                if ($name !== '' && ($rawName === '' || in_array($rawName, $staleNames, true))) {
                    $updates['name'] = $name;
                }
                foreach (['name_ar', 'name_en'] as $localizedNameColumn) {
                    $localizedName = trim((string) $user->getRawOriginal($localizedNameColumn));
                    if (in_array($localizedName, $staleNames, true)) {
                        // Let the model fall back to the verified provider name.
                        // This also repairs rows created by the old bilingual-name migration.
                        $updates[$localizedNameColumn] = null;
                    }
                }
                if (empty($user->social_id) || $user->social_provider === $provider) {
                    $updates['social_provider'] = $provider;
                    $updates['social_id'] = $providerId;
                }
                if ($picture && !$user->profile_image) {
                    $updates['profile_image'] = $picture;
                }
                $currentEmail = Str::lower((string) $user->email);
                $hasInternalEmail = Str::endsWith($currentEmail, '@placeholder.com')
                    || Str::endsWith($currentEmail, '@accounts.rokn.app');
                if ($emailIsVerified && ($email === $currentEmail || $hasInternalEmail)) {
                    if ($email !== $currentEmail) {
                        $updates['email'] = $email;
                    }
                    if (!$user->email_verified_at) {
                        // Never mark a separately edited email as verified just
                        // because the provider verified a different address.
                        $updates['email_verified_at'] = now();
                    }
                }
                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }
                if ($preferredLocale !== null && $user->preferred_locale !== $preferredLocale) {
                    $user->forceFill(['preferred_locale' => $preferredLocale])->save();
                }

                return [$user, $isNewUser];
            }, 3);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'social_login_unavailable',
                'message' => "تعذّر إكمال تسجيل الدخول\nحاول مرة أخرى",
                'data' => null,
            ], 503);
        } catch (\DomainException $e) {
            report($e);

            if ($e->getMessage() === 'social_login_predates_account_deletion') {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'social_login_expired',
                    'message' => "انتهت محاولة تسجيل الدخول\nابدأ مرة أخرى",
                    'data' => null,
                ], 410);
            }

            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'social_account_conflict',
                'message' => "هذا الحساب مرتبط بهوية أخرى\nتواصل مع الدعم إذا استمرت المشكلة",
                'data' => null,
            ], 409);
        }

        // Check if user is active
        if (!$user->active) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'account_disabled',
                'message' => "حسابك غير مفعّل\nتواصل مع الدعم",
                'data' => null,
            ], 403);
        }

        // Serialize policy evaluation with token issuance. Two first logins
        // arriving together must not both observe an empty device lock.
        $deviceSession = DB::transaction(function () use ($user, $validated): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $access = $this->deviceLogin->checkDeviceAccess(
                $lockedUser,
                $validated['device_id'] ?? null
            );
            if (!$access['allowed']) {
                return ['access' => $access, 'api_token' => null];
            }

            $this->deviceLogin->applyDeviceAction(
                $lockedUser,
                (string) $access['action'],
                (string) $access['device_id']
            );

            $apiToken = $lockedUser->generateApiToken();
            $this->deviceLogin->enforceActiveSessionLimit($lockedUser, $apiToken);

            return ['access' => $access, 'api_token' => $apiToken];
        });
        $deviceAccess = $deviceSession['access'];
        if (!$deviceAccess['allowed']) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'device_login_denied',
                'message' => $deviceAccess['message'],
                'data' => null,
            ], 403);
        }
        $apiToken = (string) $deviceSession['api_token'];

        // Push registration is recoverable on the next foreground. It must
        // never turn a verified login into a false authentication failure.
        try {
            $this->saveDeviceToken($user, $request);
        } catch (\Throwable $exception) {
            report($exception);
        }

        // This service is ledger-idempotent, so retry it on every verified login.
        // A temporary outage during first registration must not permanently lose the welcome coins.
        $welcomeBonusGranted = 0;
        try {
            $welcomeBonusGranted = \App\Services\StudentNotificationService::sendRegistrationBonus($user);
        } catch (\Throwable $exception) {
            // Authentication must never be blocked by a notification or welcome-credit outage.
            report($exception);
        }
        $user->refresh();
        $sessionProfile = (new StudentProfileResource($user))
            ->withoutLearningSnapshot()
            ->resolve($request);
        // This bearer belongs to the provider just verified. Keep that fact in
        // the session even when another linked provider originally created the
        // user row.
        $sessionProfile['social_provider'] = $provider;

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => $sessionProfile,
                'api_token' => $apiToken,
                'device_token' => $request->input('device_token') ?? $user->deviceTokens()->latest()->value('device_token') ?? null,
                'welcome_bonus_granted' => $welcomeBonusGranted,
            ]
        ]);
    }

    private function isTransientSocialProviderFailure(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (
                $current instanceof SocialProviderUnavailableException
                || $current instanceof \Illuminate\Http\Client\ConnectionException
                || $current instanceof \GuzzleHttp\Exception\ConnectException
                || $current instanceof \GuzzleHttp\Exception\ServerException
            ) {
                return true;
            }

            if ($current instanceof \Illuminate\Http\Client\RequestException) {
                $status = $current->response->status();
                if ($status === 429 || $status >= 500) {
                    return true;
                }
            }

            if (
                $current instanceof \GuzzleHttp\Exception\RequestException
                && (!$current->hasResponse() || ($current->getResponse()?->getStatusCode() ?? 0) >= 500)
            ) {
                return true;
            }
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'not configured')
            || str_contains($message, 'public-keys response')
            || str_contains($message, 'no matching apple signing key')
            || str_contains($message, 'graph api version');
    }

    public function authMethods()
    {
        $providers = $this->socialProviders->available();
        $settings = null;
        $welcomeBonus = (int) config('social_auth.welcome_bonus_coins', 20);
        try {
            $discovery = Cache::remember('auth-methods:dynamic:v2', 60, function () use ($welcomeBonus): array {
                $settings = Setting::query()->first();

                return [
                    'settings' => $settings,
                    'welcome_bonus' => RewardRule::configuredAmount(
                        'welcome_bonus',
                        (int) ($settings?->welcome_bonus_coins ?? $welcomeBonus)
                    ),
                ];
            });
            $settings = $discovery['settings'];
            $welcomeBonus = (int) $discovery['welcome_bonus'];
        } catch (\Throwable $exception) {
            // Provider discovery must stay usable during a rolling migration
            // of optional reward/settings tables. The login transaction still
            // fails normally if the core identity schema itself is unavailable.
            report($exception);
            try {
                $settings = Setting::query()->first();
                $welcomeBonus = RewardRule::configuredAmount(
                    'welcome_bonus',
                    (int) ($settings?->welcome_bonus_coins ?? $welcomeBonus)
                );
            } catch (\Throwable $databaseException) {
                report($databaseException);
            }
        }

        $publicApiUrl = rtrim(trim((string) config('social_auth.public_api_url')), '/');
        $preferredProvider = (string) ($settings?->recommended_social_provider
            ?: config('social_auth.recommended_provider', 'google'));
        $recommendedProvider = $providers->contains($preferredProvider)
            ? $preferredProvider
            : $providers->first();
        $providerBonus = $recommendedProvider === $preferredProvider
            ? max(0, (int) ($settings?->recommended_provider_bonus_coins ?? 0))
            : 0;
        $badgeAr = trim((string) ($settings?->recommended_provider_badge_ar ?? ''));
        $badgeEn = trim((string) ($settings?->recommended_provider_badge_en ?? ''));

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل طرق الدخول',
            'data' => [
                'providers' => $providers,
                'authorization_api_url' => $publicApiUrl !== ''
                    ? $publicApiUrl
                    : rtrim((string) config('app.url'), '/') . '/api/v1',
                'authorization_urls' => $providers
                    ->reject(fn (string $provider) => $provider === 'apple')
                    ->mapWithKeys(fn (string $provider) => [
                        $provider => $publicApiUrl !== ''
                            ? $publicApiUrl . '/social-auth/' . rawurlencode($provider) . '/start'
                            : route('api.social.start', ['socialProvider' => $provider]),
                    ]),
                'native_only_providers' => $providers
                    ->filter(fn (string $provider) => $provider === 'apple')
                    ->values(),
                'otp_enabled' => false,
                'password_login_visible' => false,
                'welcome_bonus_coins' => max(0, $welcomeBonus),
                'recommended_provider_bonus_coins' => $providerBonus,
                'recommended_provider_total_coins' => max(0, $welcomeBonus + $providerBonus),
                'recommended_provider' => $recommendedProvider,
                'recommendation_badge' => $recommendedProvider
                    ? ($badgeAr !== ''
                        ? str_replace('{coins}', (string) ($welcomeBonus + $providerBonus), $badgeAr)
                        : 'اختيار أسرع + ' . ($welcomeBonus + $providerBonus) . ' عملة ركن')
                    : null,
                'recommendation_badge_en' => $recommendedProvider
                    ? ($badgeEn !== ''
                        ? str_replace('{coins}', (string) ($welcomeBonus + $providerBonus), $badgeEn)
                        : 'Faster choice + ' . ($welcomeBonus + $providerBonus) . ' Rokn coins')
                    : null,
            ],
        ]);
    }

    /**
     * Kept as a stable legacy endpoint so old builds receive a clear upgrade response.
     */
    public function otpDisabled()
    {
        $providers = $this->socialProviders->labels();
        $providerNames = implode(' أو ', array_values($providers));

        return response()->json([
            'status' => 410,
            'success' => false,
            'code' => 'otp_not_supported',
            'message' => 'تسجيل الدخول متاح عبر '.($providerNames ?: 'الحسابات الاجتماعية').' دون رمز OTP.',
            'data' => [
                'providers' => array_keys($providers),
            ],
        ], 410);
    }

    /**
     * Logout user
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $validated = $request->validate([
            'device_token' => ['nullable', 'string', 'max:500'],
        ]);
        $user = auth('api')->user();

        if ($user) {
            /** @var ApiToken|null $currentToken */
            $currentToken = $request->attributes->get('rokn_api_token');
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $validated, $currentToken): void {
                $pushTokens = \App\Models\UserDeviceToken::query()
                    ->where('user_id', $user->id);
                $currentDeviceId = trim((string) $currentToken?->device_id);
                if (
                    $currentDeviceId !== ''
                    && \Illuminate\Support\Facades\Schema::hasColumn('user_device_tokens', 'device_id')
                ) {
                    $pushTokens->where('device_id', $currentDeviceId)->delete();
                } elseif (! empty($validated['device_token'])) {
                    $pushTokens->where('device_token', $validated['device_token'])->delete();
                }
                // A legacy session without a bound device or an explicit
                // native token cannot identify one installation safely. Do
                // not revoke the other signed-in phones by guessing.

                // MultipleTokensGuard revokes only the bearer token used for
                // this request. Other phones stay signed in.
                auth('api')->logout();
            });

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تسجيل الخروج بنجاح',
                'data' => null,
            ], 200);
        }

        return response()->json([
            'status' => 404,
            'success' => false,
            'message' => 'المستخدم غير موجود',
            'data' => null,
        ], 404);
    }

    /**
     * Delete user account
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAccount(Request $request)
    {
        $user = auth('api')->user();

        if (! $this->hasFreshSocialReauthentication($request, $user)) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'social_reauthentication_required',
                'message' => "أكد هويتك من جديد\nاستخدم حساب تسجيل الدخول نفسه",
                'data' => null,
            ], 403);
        }

        try {
            $cleanup = app(\App\Services\AccountDeletionService::class)->delete($user);

            if ($cleanup['local_cleanup_pending'] || $cleanup['remote_portfolio_cleanup_pending']) {
                \Illuminate\Support\Facades\Log::notice('Deleted account has deferred file cleanup.', [
                    'deleted_user_id' => $user->id,
                    'local_cleanup_pending' => $cleanup['local_cleanup_pending'],
                    'remote_portfolio_cleanup_pending' => $cleanup['remote_portfolio_cleanup_pending'],
                ]);
            }

            $cleanupPending = $cleanup['local_cleanup_pending'] || $cleanup['remote_portfolio_cleanup_pending'];
            return response()->json([
                'status' => $cleanupPending ? 202 : 200,
                'success' => true,
                'deletion_status' => $cleanupPending ? 'cleanup_pending' : 'completed',
                'message' => $cleanupPending
                    ? "تم تعطيل الحساب ومسح بياناته من التطبيق\nنستكمل حذف الملفات من التخزين"
                    : 'تم حذف الحساب وبياناته الشخصية بنجاح',
                'data' => null,
            ], $cleanupPending ? 202 : 200);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'status' => 500,
            'success' => false,
            'message' => "تعذّر حذف الحساب الآن\nحاول مرة أخرى أو تواصل مع الدعم",
            'data' => null,
        ], 500);
    }

    /**
     * Save user device token for push notifications
     *
     * @param User $user
     * @param Request $request
     * @return void
     */
    private function saveDeviceToken(User $user, Request $request)
    {
        $deviceToken = $request->input('device_token');
        $deviceType = $request->input('device_type');
        $deviceOs = $this->normalizeDeviceOs($request->input('device_os'));

        DB::transaction(function () use ($user, $request, $deviceToken, $deviceType, $deviceOs): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser || !(bool) $lockedUser->active || $lockedUser->trashed()) {
                return;
            }

            if ($deviceToken) {
                // A native token belongs to one account at a time. Reassigning
                // the unique row atomically closes reinstall/account-switch
                // delivery to its previous owner.
                $tokenAttributes = [
                    'user_id' => $lockedUser->id,
                    'device_type' => $deviceType,
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_device_tokens', 'device_os')) {
                    $tokenAttributes['device_os'] = $deviceOs;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('user_device_tokens', 'device_id')) {
                    $deviceId = trim((string) $request->input('device_id'));
                    $tokenAttributes['device_id'] = Str::isUuid($deviceId) ? $deviceId : null;
                    if ($tokenAttributes['device_id']) {
                        // One installation has one current FCM/account owner.
                        // Retire an older token before binding its replacement,
                        // including an interrupted account switch or rotation.
                        \App\Models\UserDeviceToken::query()
                            ->where('device_id', $tokenAttributes['device_id'])
                            ->where('device_token', '<>', $deviceToken)
                            ->delete();
                    }
                }

                \App\Models\UserDeviceToken::updateOrCreate(
                    ['device_token' => $deviceToken],
                    $tokenAttributes
                );
            }

            if ($deviceOs && $lockedUser->device_os !== $deviceOs) {
                $lockedUser->forceFill(['device_os' => $deviceOs])->save();
            }
        }, 3);
    }

    private function hasFreshSocialReauthentication(Request $request, User $user): bool
    {
        $plainToken = trim((string) ($request->bearerToken() ?: ''));
        if ($plainToken === '') {
            return false;
        }

        $token = ApiToken::query()
            ->where('user_id', $user->id)
            ->whereIn('token', array_values(array_unique([
                hash('sha256', $plainToken),
                $plainToken,
            ])))
            ->whereHasNotExpired()
            ->first();
        $issuedAt = $token?->issued_at;
        $window = max(60, min(600, (int) config('social_auth.account_deletion_reauth_seconds', 300)));
        if (! $issuedAt || $issuedAt->isBefore(now()->subSeconds($window)) || $issuedAt->isFuture()) {
            return false;
        }

        // The presented bearer must be minted in the same short window as a
        // provider verification for this exact user. A fresh generic token or
        // another account's OAuth response cannot authorize deletion.
        $provider = strtolower(trim((string) $user->social_provider));
        $providerUserId = trim((string) $user->social_id);
        if ($provider === '' || $providerUserId === '') {
            return false;
        }

        return SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->where('last_verified_at', '>=', now()->subSeconds($window))
            ->where('last_verified_at', '<=', $issuedAt->copy()->addSeconds(5))
            ->exists();
    }

    private function normalizeDeviceOs($deviceOs): ?string
    {
        $value = strtolower(trim((string) $deviceOs));

        if (str_starts_with($value, 'android')) {
            return 'android';
        }

        if (str_starts_with($value, 'ios')) {
            return 'ios';
        }

        return null;
    }

    /**
     * Standalone endpoint to save or refresh device token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDeviceToken(Request $request)
    {
        $request->validate([
            'device_token' => 'required|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_os' => 'nullable|string|max:255',
            'device_id' => ['nullable', 'uuid'],
        ]);

        $user = auth()->user() ?? auth('api')->user();

        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'غير مصرح',
                'data' => null,
            ], 401);
        }

        /** @var ApiToken|null $currentToken */
        $currentToken = $request->attributes->get('rokn_api_token');
        $currentDeviceId = trim((string) $currentToken?->device_id);
        if ($currentDeviceId !== '') {
            // Bind push to the authenticated session, not to a stale or
            // caller-supplied installation id left over after account switch.
            $request->merge(['device_id' => $currentDeviceId]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $request): void {
            $this->saveDeviceToken($user, $request);
            // Token rotation is transport maintenance, not consent. Reopening
            // the app or refreshing FCM must not silently undo an explicit
            // notification opt-out saved on the profile.
        });
        $user->refresh();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حفظ رمز التنبيهات بنجاح',
            'data' => [
                'device_token' => $request->input('device_token'),
                'user' => (new StudentProfileResource($user))->withoutLearningSnapshot(),
            ]
        ]);
    }

    /**
     * Remove this installation's push token without logging the learner out.
     * Turning notifications off must stop delivery immediately and must not
     * leave a reusable token attached to the account.
     */
    public function deleteDeviceToken(Request $request)
    {
        $validated = $request->validate([
            'device_token' => 'required|string|max:500',
        ]);

        $user = auth()->user() ?? auth('api')->user();
        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'غير مصرح',
                'data' => null,
            ], 401);
        }

        \App\Models\UserDeviceToken::query()
            ->where('user_id', $user->id)
            ->where('device_token', $validated['device_token'])
            ->delete();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم إيقاف تنبيهات هذا الجهاز',
            'data' => null,
        ]);
    }
}
