<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\LoginRequest;
use App\Http\Requests\API\RegisterRequest;
use App\Http\Resources\StudentProfileResource;
use App\Http\Resources\UserResource;
use App\Models\SocialAccount;
use App\Models\Setting;
use App\Models\CoinEarningMethod;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\VerificationCode;
use App\Models\RewardRule;
use App\Services\WhatsAppService;
use App\Services\FacebookService;
use App\Services\GoogleService;
use App\Services\TikTokService;
use App\Services\AppleService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Hashing\BcryptHasher;

class SignController extends Controller
{
    private $whatsAppService;
    private $facebookService;
    private $googleService;
    private $tikTokService;
    private $appleService;

    public function __construct(
        WhatsAppService $whatsAppService,
        FacebookService $facebookService,
        GoogleService $googleService,
        TikTokService $tikTokService,
        AppleService $appleService
    ) {
        $this->whatsAppService = $whatsAppService;
        $this->facebookService = $facebookService;
        $this->googleService = $googleService;
        $this->tikTokService = $tikTokService;
        $this->appleService = $appleService;
    }

    /**
     * Register a new user
     *
     * @param RegisterRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'email' => $request->input('phone') . '@placeholder.com',
            'password' => (new BcryptHasher())->make($request->input('password')),
            'device_os' => $this->normalizeDeviceOs($request->input('device_os')),
        ]);
        $user->forceFill(['role' => 'client', 'active' => true])->save();

        $user->generateApiToken();

        // Save device token if provided
        $this->saveDeviceToken($user, $request);

        // Grant registration bonus coins and send localized FCM notification
        $welcomeBonusGranted = \App\Services\StudentNotificationService::sendRegistrationBonus($user);
        $user->refresh();

        // Create verification code and send via WhatsApp
       // $verificationCode = VerificationCode::createForPhone($user->phone, 'verification');
       // $this->whatsAppService->sendVerificationCode($user->phone, $verificationCode->code);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم التسجيل بنجاح!',
            'data' => [
                'user' => new StudentProfileResource($user),
                'requires_verification' => true,
                'device_token' => $request->input('device_token') ?? $user->deviceTokens()->latest()->value('device_token') ?? null,
                'welcome_bonus_granted' => $welcomeBonusGranted,
            ]
        ]);
    }

    /**
     * Login user
     *
     * @param LoginRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(LoginRequest $request)
    {
        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'لا يوجد مستخدم بهذه البيانات',
                'errors' => ['phone' => ['لا يوجد مستخدم بهذا الرقم']]
            ], 422);
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'message' => 'كلمة المرور غير صحيحة',
                'errors' => ['password' => ['كلمة المرور غير صحيحة']]
            ], 422);
        }
/*
        // Check if phone is verified
        if (!$user->phone_verified_at) {
            // Send a new verification code
            $verificationCode = VerificationCode::createForPhone($user->phone, 'verification');
            $this->whatsAppService->sendVerificationCode($user->phone, $verificationCode->code);

            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'حسابك غير مفعل. تم إرسال رمز التحقق إلى واتساب الخاص بك.',
                'data' => [
                    'requires_verification' => true,
                    'phone' => $user->phone,
                ]
            ], 403);
        }
*/
        // Check if user is active
        if (!$user->active) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'حسابك غير مفعل. يرجى التواصل مع الدعم الفني.',
            ], 403);
        }

        Auth::login($user);

        $apiToken = $user->generateApiToken();

        // Save device token if provided
        $this->saveDeviceToken($user, $request);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => new StudentProfileResource($user),
                'api_token' => $apiToken,
                'device_token' => $request->input('device_token') ?? $user->deviceTokens()->latest()->value('device_token') ?? null,
            ]
        ]);
    }

    /**
     * Social Login with provider-side token verification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function socialLogin(Request $request)
    {
        $nonceRules = $request->input('provider') === 'apple'
            ? ['bail', 'required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/']
            : ['nullable', 'string', 'max:255'];

        $validated = $request->validate([
            'provider' => 'required|string|in:facebook,google,tiktok,apple',
            'token' => 'required|string|max:10000',
            'provider_name' => 'nullable|string|max:255',
            'nonce' => $nonceRules,
            'device_os' => 'nullable|string|max:255',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'preferred_locale' => 'nullable|string|in:ar,en',
        ]);

        $provider = $validated['provider'];
        $token = $validated['token'];
        $localeInput = $validated['preferred_locale']
            ?? ($request->hasHeader('Accept-Language') ? $request->header('Accept-Language') : null);
        $preferredLocale = $localeInput === null
            ? null
            : (str_starts_with(strtolower((string) $localeInput), 'en') ? 'en' : 'ar');

        try {
            // Verify token with appropriate service
            $socialData = match($provider) {
                'facebook' => $this->facebookService->verify($token),
                'google' => $this->googleService->verify($token),
                'tiktok' => $this->tikTokService->verify($token),
                'apple' => $this->appleService->verify($token, (string) $validated['nonce']),
                default => throw new Exception('Unsupported provider'),
            };
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Social identity verification failed', [
                'provider' => $provider,
                'exception' => get_class($e),
            ]);

            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'social_identity_verification_failed',
                'message' => 'تعذر التحقق من حسابك الآن. أعد المحاولة من شاشة تسجيل الدخول.',
            ], 422);
        }

        $providerId = trim((string) ($socialData['id'] ?? ''));
        if ($providerId === '' || strlen($providerId) > 191) {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'social_identity_verification_failed',
                'message' => 'تعذر التحقق من هوية الحساب.',
            ], 422);
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
                $picture
            ): array {
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
                    $user = User::query()->where('email', $email)->lockForUpdate()->first();
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
                        $user->forceFill(['portfolio_slug' => 'student-' . $user->id])->save();
                    }
                }

                $conflictingAccount = SocialAccount::query()
                    ->where('user_id', $user->id)
                    ->where('provider', $provider)
                    ->where('provider_user_id', '!=', $providerId)
                    ->exists();
                if ($conflictingAccount) {
                    throw new Exception('This provider is already linked to another identity.');
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

                return [$user, $isNewUser];
            });
        } catch (Exception $e) {
            report($e);

            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'social_account_conflict',
                'message' => 'هذا الحساب مرتبط بهوية أخرى. تواصل مع الدعم إذا استمرت المشكلة.',
            ], 409);
        }

        if ($preferredLocale !== null && $user->preferred_locale !== $preferredLocale) {
            $user->forceFill(['preferred_locale' => $preferredLocale])->save();
        }

        // Check if user is active
        if (!$user->active) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'message' => 'حسابك غير مفعل. يرجى التواصل مع الدعم الفني.',
            ], 403);
        }

        // API authentication is token based. Starting a web/session login here
        // can fail on stateless API routes and can leak a previous browser
        // session into a newly verified social identity.
        $apiToken = $user->generateApiToken();

        // Save device token if provided
        $this->saveDeviceToken($user, $request);

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

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح',
            'data' => [
                'user' => new StudentProfileResource($user),
                'api_token' => $apiToken,
                'device_token' => $request->input('device_token') ?? $user->deviceTokens()->latest()->value('device_token') ?? null,
                'welcome_bonus_granted' => $welcomeBonusGranted,
            ]
        ]);
    }

    public function authMethods()
    {
        $providers = collect(config('social_auth.providers', ['facebook', 'google', 'tiktok', 'apple']))
            ->filter(fn (string $provider) => match ($provider) {
                'google' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
                'facebook' => filled(config('services.facebook.client_id')) && filled(config('services.facebook.client_secret')),
                'tiktok' => filled(config('services.tiktok.client_key')) && filled(config('services.tiktok.client_secret')),
                'apple' => filled(config('services.apple.client_id')),
                default => false,
            })
            ->values();
        $welcomeBonus = RewardRule::configuredAmount(
            'welcome_bonus',
            (int) (Setting::query()->value('welcome_bonus_coins')
                ?? config('social_auth.welcome_bonus_coins', 20))
        );

        $publicApiUrl = rtrim(trim((string) config('social_auth.public_api_url')), '/');
        $preferredProvider = (string) config('social_auth.recommended_provider', 'facebook');
        $recommendedProvider = $providers->contains($preferredProvider)
            ? $preferredProvider
            : $providers->first();

        return response()->json([
            'status' => 200,
            'success' => true,
            'data' => [
                'providers' => $providers,
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
                'recommended_provider' => $recommendedProvider,
                'recommendation_badge' => $recommendedProvider
                    ? config('social_auth.recommendation_badge')
                    : null,
            ],
        ]);
    }

    /**
     * Kept as a stable legacy endpoint so old builds receive a clear upgrade response.
     */
    public function otpDisabled()
    {
        return response()->json([
            'status' => 410,
            'success' => false,
            'code' => 'otp_not_supported',
            'message' => 'تسجيل الدخول متاح عبر Google أو Facebook أو TikTok دون رمز OTP.',
            'data' => [
                'providers' => config('social_auth.providers', ['google', 'facebook', 'tiktok']),
            ],
        ], 410);
    }

    /**
     * Send verification code via WhatsApp
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendVerificationCode(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'لا يوجد مستخدم بهذا الرقم',
            ], 404);
        }

        if ($user->phone_verified_at) {
            return response()->json([
                'status' => 400,
                'success' => false,
                'message' => 'رقم الهاتف مفعل بالفعل',
            ], 400);
        }

        $verificationCode = VerificationCode::createForPhone($user->phone, 'verification');
        $this->whatsAppService->sendVerificationCode($user->phone, $verificationCode->code);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم إرسال رمز التحقق إلى واتساب الخاص بك',
        ]);
    }

    /**
     * Verify phone number with code
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
            'device_token' => 'nullable|string|max:500',
            'device_type' => 'nullable|string|max:50',
            'device_os' => 'nullable|string|max:255',
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'لا يوجد مستخدم بهذا الرقم',
            ], 404);
        }

        $verificationCode = VerificationCode::findValidCode(
            $request->input('phone'),
            $request->input('code'),
            'verification'
        );

        if (!$verificationCode) {
            return response()->json([
                'status' => 400,
                'success' => false,
                'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية',
            ], 400);
        }

        $verificationCode->markAsUsed();
        $user->update(['phone_verified_at' => now()]);

        Auth::login($user);
        $apiToken = $user->generateApiToken();

        // Save device token if provided
        $this->saveDeviceToken($user, $request);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تفعيل رقم الهاتف بنجاح',
            'data' => [
                'user' => new StudentProfileResource($user),
                'api_token' => $apiToken,
                'device_token' => $request->input('device_token') ?? $user->deviceTokens()->latest()->value('device_token') ?? null,
            ]
        ]);
    }

    /**
     * Request password reset via WhatsApp
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'لا يوجد مستخدم بهذا الرقم',
            ], 404);
        }

        $verificationCode = VerificationCode::createForPhone($user->phone, 'password_reset');
        $this->whatsAppService->sendPasswordResetCode($user->phone, $verificationCode->code);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم إرسال رمز إعادة تعيين كلمة المرور إلى واتساب الخاص بك',
        ]);
    }

    /**
     * Reset password with verification code
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string',
            'password' => 'required|confirmed|min:6',
        ]);

        $user = User::where('phone', $request->input('phone'))->first();

        if (!$user) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'لا يوجد مستخدم بهذا الرقم',
            ], 404);
        }

        $verificationCode = VerificationCode::findValidCode(
            $request->input('phone'),
            $request->input('code'),
            'password_reset'
        );

        if (!$verificationCode) {
            return response()->json([
                'status' => 400,
                'success' => false,
                'message' => 'رمز التحقق غير صحيح أو منتهي الصلاحية',
            ], 400);
        }

        $verificationCode->markAsUsed();
        $user->update([
            'password' => (new BcryptHasher())->make($request->input('password')),
        ]);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تغيير كلمة المرور بنجاح. يمكنك تسجيل الدخول الآن.',
        ]);
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
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $validated): void {
                if (! empty($validated['device_token'])) {
                    \App\Models\UserDeviceToken::where('user_id', $user->id)
                        ->where('device_token', $validated['device_token'])
                        ->delete();
                }

                // MultipleTokensGuard revokes only the bearer token used for
                // this request. Other phones stay signed in.
                auth('api')->logout();
            });

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تسجيل الخروج بنجاح'
            ], 200);
        }

        return response()->json([
            'status' => 404,
            'success' => false,
            'message' => 'المستخدم غير موجود'
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
                'message' => 'أكد هويتك من جديد بنفس حساب تسجيل الدخول قبل حذف الحساب.',
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
                    ? 'تم تعطيل الحساب ومسح بياناته من التطبيق. جارٍ استكمال حذف الملفات من التخزين.'
                    : 'تم حذف الحساب وبياناته الشخصية بنجاح',
            ], $cleanupPending ? 202 : 200);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json([
            'status' => 500,
            'success' => false,
            'message' => 'تعذر حذف الحساب الآن. حاول مرة أخرى أو تواصل مع الدعم'
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

        if ($deviceToken) {
            // Update or create the token for this user
            // We use updateOrCreate to avoid duplicate tokens for the same user
            // If the token already exists for another user, it will be reassigned to this user
            $tokenAttributes = [
                'user_id' => $user->id,
                'device_type' => $deviceType,
            ];
            // Older installations did not have this column. Keep sign-in
            // working during a rolling deploy, then persist it after migrate.
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_device_tokens', 'device_os')) {
                $tokenAttributes['device_os'] = $deviceOs;
            }

            \App\Models\UserDeviceToken::updateOrCreate(
                ['device_token' => $deviceToken],
                $tokenAttributes
            );
        }

        if ($deviceOs && $user->device_os !== $deviceOs) {
            $user->update(['device_os' => $deviceOs]);
        }
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
        return SocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', strtolower(trim((string) $user->social_provider)))
            ->where('provider_user_id', trim((string) $user->social_id))
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
        ]);

        $user = auth()->user() ?? auth('api')->user();

        if (!$user) {
            return response()->json([
                'status' => 401,
                'success' => false,
                'message' => 'غير مصرح'
            ], 401);
        }

        $this->saveDeviceToken($user, $request);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حفظ رمز التنبيهات بنجاح',
            'data' => [
                'device_token' => $request->input('device_token'),
                'user' => new StudentProfileResource($user),
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
        ]);
    }
}
