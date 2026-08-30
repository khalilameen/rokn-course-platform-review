<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\AiPlanLimitReachedException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Setting;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseChatAccessService;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class CourseChatController extends Controller
{
    private ?Setting $runtimeSettings = null;

    public function __construct(
        private readonly CourseAccessPlanService $accessPlans,
        private readonly AiEntitlementBudgetService $entitlementBudget
    ) {
    }

    public function sendForCourse(
        Request $request,
        Course $course,
        OpenRouterService $openRouter,
        CourseChatAccessService $access
    ): JsonResponse
    {
        $request->merge(['course_id' => $course->id]);

        return $this->send($request, $openRouter, $access);
    }

    public function send(
        Request $request,
        OpenRouterService $openRouter,
        CourseChatAccessService $access
    ): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'message' => 'required|string|min:1|max:1600',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
            'reel_title' => 'nullable|string|max:160',
        ]);

        $user = auth('api')->user();
        $course = Course::findOrFail($validated['course_id']);
        if (!$access->hasLearningAccess($user->id, $course->id)) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'course_access_required',
                'message' => 'افتح الكورس أولًا لاستخدام Rokn AI',
                'data' => null,
            ], 403);
        }
        if (!$course->ai_chat_enabled) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'chat_disabled_for_course',
                'message' => 'Rokn AI غير متاح في الكورس ده',
                'data' => null,
            ], 403);
        }
        if (!$access->hasChatAccess($user->id, $course->id)) {
            return response()->json([
                'status' => 403,
                'success' => false,
                'code' => 'chat_upgrade_required',
                'message' => 'Rokn AI غير موجود في طريقة التعلّم الحالية. '
                    . 'الكورس والمشروعات مفتوحان، ويمكنك الترقية لو احتجت تسأل المدرب.',
                'data' => null,
            ], 403);
        }

        $question = trim(strip_tags((string) $validated['message']));
        if ($question === '') {
            return response()->json([
                'status' => 422,
                'success' => false,
                'code' => 'empty_chat_message',
                'message' => 'اكتب سؤالك الأول',
                'data' => null,
            ], 422);
        }

        $minuteKey = sprintf('course-chat:minute:%d:%d', $user->id, $course->id);
        $perMinute = max(1, (int) config('openrouter.per_minute_limit', 8));
        if (RateLimiter::tooManyAttempts($minuteKey, $perMinute)) {
            return $this->gracefulUnavailable(
                "استنى لحظات وابعت سؤالك تاني\nالفيديو ومكانك في الكورس محفوظين",
                RateLimiter::availableIn($minuteKey),
                'chat_rate_limited'
            );
        }
        RateLimiter::hit($minuteKey, 60);

        $currentStepTitle = trim(strip_tags((string) ($validated['reel_title'] ?? '')));
        if (!empty($validated['lesson_id'])) {
            $lesson = Lesson::query()
                ->where('id', $validated['lesson_id'])
                ->where('list_id', $course->id)
                ->first();
            if (!$lesson) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'code' => 'lesson_course_mismatch',
                    'message' => 'الخطوة المختارة لا تنتمي إلى هذا الكورس',
                    'data' => null,
                ], 422);
            }
            $currentStepTitle = (string) $lesson->title;
        }

        $messages = [[
            'role' => 'system',
            'content' => $this->courseBrief($course),
        ]];
        if ($currentStepTitle !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => 'عنوان الخطوة التي يشاهدها الطالب الآن: '
                    . Str::limit($currentStepTitle, 160, ''),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $enrollment = $access->activeEnrollmentFor((int) $user->id, (int) $course->id);
        $planTerms = $enrollment
            ? $this->accessPlans->termsForEnrollment($enrollment)
            : null;
        $model = $this->resolveModel($course, $planTerms['model_override'] ?? null);
        $maxTokens = min(
            (int) ($course->tokens_number ?: config('openrouter.max_tokens', 420)),
            (int) (($planTerms['max_output_tokens'] ?? null) ?: config('openrouter.max_tokens', 420)),
            (int) config('openrouter.max_tokens', 420)
        );
        $estimatedTokens = $maxTokens + (int) ceil(array_sum(array_map(
            static fn (array $message): int => strlen((string) ($message['content'] ?? '')),
            $messages
        )) / 4);
        $planBudget = $this->entitlementBudget;
        $answerCacheKey = sprintf(
            'course-chat:answer:v6:%d:%s:%s:%s',
            $course->id,
            sha1((string) $course->updated_at),
            sha1(Str::lower($currentStepTitle)),
            sha1(Str::lower($question))
        );

        try {
            $answer = Cache::get($answerCacheKey);
            $wasCached = is_array($answer);

            if (!$wasCached) {
                $result = Cache::lock(
                    'lock:' . $answerCacheKey,
                    max(15, (int) config('openrouter.timeout_seconds', 20) + 5)
                )->block(3, function () use (
                    $answerCacheKey,
                    $user,
                    $openRouter,
                    $model,
                    $messages,
                    $course,
                    $enrollment,
                    $maxTokens,
                    $estimatedTokens,
                    $planBudget
                ): array {
                    $cachedAnswer = Cache::get($answerCacheKey);
                    if (is_array($cachedAnswer)) {
                        return [
                            'answer' => $cachedAnswer,
                            'cached' => true,
                            'quota' => true,
                            'global_budget' => true,
                            'plan_budget' => true,
                        ];
                    }

                    $dailyKey = sprintf(
                        'course-chat:daily:%s:%s',
                        now()->format('Y-m-d'),
                        $enrollment ? 'enrollment-'.$enrollment->id : 'user-'.$user->id.'-course-'.$course->id
                    );
                    $dailyLimit = $this->cappedPositiveSetting(
                        'ai_daily_user_limit',
                        'openrouter.daily_user_limit',
                        100
                    );
                    if (!$this->consumeDailyQuota($dailyKey, $dailyLimit)) {
                        return [
                            'answer' => null,
                            'cached' => false,
                            'quota' => false,
                            'global_budget' => true,
                            'plan_budget' => true,
                        ];
                    }

                    try {
                        $reservation = $enrollment
                            ? $planBudget->reserve($enrollment, 'course_chat', $estimatedTokens, $model)
                            : null;
                    } catch (AiPlanLimitReachedException $exception) {
                        return [
                            'answer' => null,
                            'cached' => false,
                            'quota' => true,
                            'global_budget' => true,
                            'plan_budget' => false,
                        ];
                    }

                    // A caller must first reserve from its own immutable plan
                    // budget. Otherwise an exhausted/abusive account could
                    // repeatedly consume the platform emergency counter and
                    // degrade service for unrelated paying learners.
                    if (!$this->consumeGlobalBudget($maxTokens)) {
                        $planBudget->release($reservation, 'platform_emergency_budget_reached');

                        return [
                            'answer' => null,
                            'cached' => false,
                            'quota' => true,
                            'global_budget' => false,
                            'plan_budget' => true,
                        ];
                    }

                    try {
                        $freshAnswer = $openRouter->chat(
                            $model,
                            $messages,
                            (float) ($course->temperature ?? 0.45),
                            $maxTokens
                        );
                        $planBudget->settle($reservation, $freshAnswer);
                    } catch (\Throwable $exception) {
                        $planBudget->release($reservation, $exception->getMessage());
                        throw $exception;
                    }
                    Cache::put(
                        $answerCacheKey,
                        $freshAnswer,
                        now()->addMinutes($this->cappedPositiveSetting(
                            'ai_answer_cache_minutes',
                            'openrouter.answer_cache_minutes',
                            360
                        ))
                    );

                    return [
                        'answer' => $freshAnswer,
                        'cached' => false,
                        'quota' => true,
                        'global_budget' => true,
                        'plan_budget' => true,
                    ];
                });

                if (!$result['plan_budget']) {
                    return response()->json([
                        'status' => 403,
                        'success' => false,
                        'code' => 'chat_plan_limit_reached',
                        'message' => 'استخدمت مساحة Rokn AI المتاحة في خطتك. '
                            . 'تعلّمك ومشروعاتك ما زالا مفتوحين، ويمكنك الترقية لو احتجت أسئلة أكثر.',
                        'data' => null,
                    ], 403);
                }

                if (!$result['global_budget']) {
                    return $this->gracefulUnavailable(
                        "Rokn AI وصل لحد الاستخدام المتاح دلوقتي\nجرب تاني بكرة وتقدمك محفوظ",
                        $this->secondsUntilEndOfDay(),
                        'ai_capacity_reached'
                    );
                }

                if (!$result['quota']) {
                    return $this->gracefulUnavailable(
                        "Rokn AI وصل لحد الاستخدام اليومي\nمكانك محفوظ وجرب تاني بكرة",
                        $this->secondsUntilEndOfDay(),
                        'chat_daily_limit_reached'
                    );
                }

                $answer = $result['answer'];
                $wasCached = $result['cached'];
            }
        } catch (LockTimeoutException $exception) {
            return $this->gracefulUnavailable(
                "السؤال بيتجهز دلوقتي\nجرب إرساله تاني بعد لحظات",
                3,
                'chat_answer_in_progress'
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->gracefulUnavailable(
                "Rokn AI مش متاح للحظات\nكمل المشاهدة وجرب تاني بعد شوية\nتقدمك محفوظ",
                45,
                'ai_temporarily_unavailable'
            );
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Chat response generated successfully',
            'data' => [
                'message' => (string) ($answer['message'] ?? ''),
                'unavailable' => false,
                'cached' => $wasCached,
            ],
        ]);
    }

    private function consumeDailyQuota(string $key, int $limit): bool
    {
        return Cache::lock('lock:' . $key, 5)->block(2, function () use ($key, $limit): bool {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return false;
            }

            RateLimiter::hit($key, $this->secondsUntilEndOfDay());

            return true;
        });
    }

    private function consumeGlobalBudget(int $reservedTokens): bool
    {
        $day = now()->format('Y-m-d');
        $month = now()->format('Y-m');
        $requestLimit = $this->cappedBudgetSetting(
            'ai_global_daily_request_limit',
            'openrouter.global_daily_request_limit'
        );
        $dailyTokenLimit = $this->cappedBudgetSetting(
            'ai_global_daily_token_budget',
            'openrouter.global_daily_token_budget'
        );
        $monthlyTokenLimit = $this->cappedBudgetSetting(
            'ai_global_monthly_token_budget',
            'openrouter.global_monthly_token_budget'
        );
        $reservedTokens = max(1, $reservedTokens);
        if ($requestLimit === 0 || $dailyTokenLimit === 0 || $monthlyTokenLimit === 0) {
            return false;
        }

        return Cache::lock('openrouter:global-budget-lock', 5)->block(2, function () use (
            $day,
            $month,
            $requestLimit,
            $dailyTokenLimit,
            $monthlyTokenLimit,
            $reservedTokens
        ): bool {
            $requestKey = 'openrouter:global:requests:' . $day;
            $dailyTokenKey = 'openrouter:global:tokens:' . $day;
            $monthlyTokenKey = 'openrouter:global:tokens:' . $month;
            if (RateLimiter::tooManyAttempts($requestKey, $requestLimit)) {
                return false;
            }

            $dailyUsed = max(0, (int) Cache::get($dailyTokenKey, 0));
            $monthlyUsed = max(0, (int) Cache::get($monthlyTokenKey, 0));
            if (
                $dailyUsed + $reservedTokens > $dailyTokenLimit
                || $monthlyUsed + $reservedTokens > $monthlyTokenLimit
            ) {
                return false;
            }

            RateLimiter::hit($requestKey, $this->secondsUntilEndOfDay());
            Cache::put($dailyTokenKey, $dailyUsed + $reservedTokens, now()->endOfDay());
            Cache::put($monthlyTokenKey, $monthlyUsed + $reservedTokens, now()->endOfMonth());

            return true;
        });
    }

    private function courseBrief(Course $course): string
    {
        $version = sha1(implode('|', [
            (string) $course->updated_at,
            (string) $course->chat_ai_prompt,
            (string) $course->description_ar,
            (string) $course->description_en,
        ]));

        return Cache::remember(
            sprintf('course-chat:brief:v5:%d:%s', $course->id, $version),
            now()->addHours(12),
            function () use ($course): string {
                $direction = trim((string) (
                    $course->chat_ai_prompt
                    ?: $course->description_ar
                    ?: $course->description_en
                ));
                $direction = preg_replace('/\s+/u', ' ', strip_tags($direction)) ?: '';
                $direction = Str::limit($direction, 850, '');

                return implode("\n", array_filter([
                    'أنت Rokn AI ومدرب خبير داخل منصة كورسات مصرية مبنية على خطوات فيديو تعليمية قصيرة',
                    'جاوب سؤال الطالب مباشرة بالمقدار الذي يحتاجه السؤال من غير حشو ولا اختصار يضيع المعلومة',
                    'جاوب الأسئلة العامة أيضًا حتى لا يضطر الطالب للخروج من التطبيق ولا تدّعي معلومة غير مؤكدة',
                    'اكتب كمدرب مصري حقيقي في شات وليس كشخصية مساعد آلي',
                    'ادخل في الإجابة فورًا ولا تبدأ بتحية أو إعادة صياغة السؤال ولا تذكر أنك ذكاء اصطناعي',
                    'ممنوع عبارات مثل سؤال رائع أو أحسنت أو أي كلام تحفيزي جاهز',
                    'لا تختم بسؤال ولا تعرض مساعدة إضافية بعد الإجابة',
                    'لا تستخدم الفاصلة أو النقطة ونظم الكلام بسطور قصيرة بدل علامات الترقيم',
                    'يمكن استخدام الأقواس وعلامة الاستفهام والتعجب عند الضرورة فقط',
                    'اكتب المصطلح الشائع بالإنجليزية كما هو ثم اشرح معناه بالعامية المصرية عند الحاجة',
                    'استخدم سياق الكورس والخطوة الحالية عندما يفيد الإجابة ولا تضف ملخصًا أو أقسامًا لم يطلبها الطالب',
                    'اسم الكورس: ' . ($course->name_ar ?: $course->name_en),
                    $direction !== '' ? 'اتجاه الكورس وأفكاره باختصار: ' . $direction : null,
                ]));
            }
        );
    }

    private function secondsUntilEndOfDay(): int
    {
        // Carbon 3 returns floating-point, direction-aware differences. Rate
        // limiter decay expects an integer number of seconds.
        return max(1, (int) ceil(now()->diffInSeconds(now()->endOfDay(), true)));
    }

    private function resolveModel(Course $course, ?string $planOverride = null): string
    {
        $default = trim((string) config('openrouter.default_model'));
        $requested = trim((string) ($planOverride ?: $course->ai_model_type));
        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));

        if ($allowed === [] || !in_array($default, $allowed, true)) {
            throw new \RuntimeException('AI model allowlist is not configured safely.');
        }

        if ($requested !== '' && in_array($requested, $allowed, true)) {
            return $requested;
        }

        return $default;
    }

    /**
     * Product controls in the dashboard can lower an operations cap but can
     * never silently raise the ceiling configured by the deployment owner.
     */
    private function cappedPositiveSetting(string $field, string $configKey, int $fallback): int
    {
        $operationsCap = max(1, (int) config($configKey, $fallback));
        $dashboardCap = (int) ($this->settings()->{$field} ?? 0);

        return $dashboardCap > 0 ? min($operationsCap, $dashboardCap) : $operationsCap;
    }

    /**
     * A zero environment budget is deliberately fail-closed. Dashboard users
     * cannot turn a provider back on or exceed the infrastructure budget.
     */
    private function cappedBudgetSetting(string $field, string $configKey): int
    {
        $operationsCap = max(0, (int) config($configKey, 0));
        if ($operationsCap === 0) {
            return 0;
        }

        $dashboardCap = (int) ($this->settings()->{$field} ?? 0);

        return $dashboardCap > 0 ? min($operationsCap, $dashboardCap) : $operationsCap;
    }

    private function settings(): Setting
    {
        return $this->runtimeSettings ??= (Setting::query()->first() ?? new Setting());
    }

    private function gracefulUnavailable(string $message, int $retryAfter, string $code): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'code' => $code,
            'message' => 'Chat temporarily unavailable',
            'data' => [
                'message' => $message,
                'unavailable' => true,
                'can_retry' => true,
                'retry_after_seconds' => max(1, $retryAfter),
            ],
        ]);
    }
}
