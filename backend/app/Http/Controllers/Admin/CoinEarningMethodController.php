<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\RewardRule;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\SocialAuthProviderRegistry;
use App\Support\BusinessClock;
use App\Support\AdminSingletonLock;

class CoinEarningMethodController extends Controller
{
    public function index(SocialAuthProviderRegistry $socialProviders)
    {
        $methods  = CoinEarningMethod::withCount('userEarnings')
            ->latest()
            ->latest('id')
            ->paginate(10)
            ->withQueryString();
        $setting = Setting::first();
        $rewardRules = RewardRule::query()->orderBy('sort_order')->orderBy('id')->get();
        $rewardEvents = RewardRule::EVENTS;
        $socialProviderLabels = $socialProviders->labels();
        $settingsEditorVersion = $this->settingsEditorVersion($setting);
        $rewardRuleEditorVersions = $rewardRules->mapWithKeys(
            fn (RewardRule $rule): array => [$rule->id => $this->rewardRuleEditorVersion($rule)]
        );
        $methodEditorVersions = $methods->getCollection()->mapWithKeys(
            fn (CoinEarningMethod $method): array => [$method->id => $this->methodEditorVersion($method)]
        );
        return view('admin.coin_earning_methods.index', compact(
            'methods', 'setting', 'rewardRules', 'rewardEvents', 'socialProviderLabels',
            'settingsEditorVersion', 'rewardRuleEditorVersions', 'methodEditorVersions'
        ));
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'how_to_use_coins_ar' => 'nullable|string',
            'how_to_use_coins_en' => 'nullable|string',
            'reward_balance_cap' => 'required|integer|min:0|max:1000000',
            'max_reward_contribution_per_course' => 'required|integer|min:0|max:1000000',
            'recommended_social_provider' => [
                'required',
                'string',
                Rule::in(app(SocialAuthProviderRegistry::class)->declared()->all()),
            ],
            'recommended_provider_bonus_coins' => 'required|integer|min:0|max:1000000',
            'recommended_provider_badge_ar' => 'nullable|string|max:255',
            'recommended_provider_badge_en' => 'nullable|string|max:255',
            'editor_version' => 'required|string|size:64',
        ]);

        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        DB::transaction(function () use ($validated, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $setting = Setting::query()->lockForUpdate()->first();
            if (!$setting) {
                if (!hash_equals($this->settingsEditorVersion(null), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                    ]);
                }
                $setting = Setting::query()->create([]);
            } elseif (!hash_equals($this->settingsEditorVersion($setting), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $setting->update($validated);
        }, 3);
        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قواعد ومكافآت العملات بنجاح');
    }

    public function create()
    {
        return view('admin.coin_earning_methods.create');
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255',
            'campaign_key' => 'nullable|string|max:80|regex:/^[A-Za-z0-9._:-]+$/|unique:coin_earning_methods,campaign_key',
            'action_url' => 'nullable|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'total_claim_limit' => 'nullable|integer|min:1|max:10000000',
            'is_active' => 'boolean',
            'authoring_request_id' => 'required|uuid',
        ]);

        $payload = $request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'campaign_key', 'requires_external_visit', 'verification_delay_seconds',
            'starts_at', 'ends_at', 'total_claim_limit', 'is_active',
        ]);
        $payload['is_repeatable'] = false;
        foreach (['starts_at', 'ends_at'] as $field) {
            $payload[$field] = BusinessClock::localInputToUtc($payload[$field] ?? null);
        }
        $this->ensureUsableDestination($payload);
        DB::transaction(function () use ($request, $payload, $createIntents): void {
            $method = CoinEarningMethod::create($payload);
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                CoinEarningMethod::class,
                $method->id
            );
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم إضافة طريقة ربح العملات بنجاح');
    }

    public function edit(CoinEarningMethod $coinEarningMethod)
    {
        $editorVersion = $this->methodEditorVersion($coinEarningMethod);
        return view('admin.coin_earning_methods.edit', compact('coinEarningMethod', 'editorVersion'));
    }

    public function update(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255',
            'campaign_key' => 'nullable|string|max:80|regex:/^[A-Za-z0-9._:-]+$/|unique:coin_earning_methods,campaign_key,' . $coinEarningMethod->id,
            'action_url' => 'nullable|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'total_claim_limit' => 'nullable|integer|min:1|max:10000000',
            'is_active' => 'boolean',
            'editor_version' => 'required|string|size:64',
        ]);

        $payload = $request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'campaign_key', 'requires_external_visit', 'verification_delay_seconds',
            'starts_at', 'ends_at', 'total_claim_limit', 'is_active',
        ]);
        $payload['is_repeatable'] = false;
        foreach (['starts_at', 'ends_at'] as $field) {
            $payload[$field] = BusinessClock::localInputToUtc($payload[$field] ?? null);
        }
        $editorVersion = (string) $request->input('editor_version');
        try {
            DB::transaction(function () use ($coinEarningMethod, $payload, $editorVersion): void {
                $locked = CoinEarningMethod::query()
                    ->whereKey($coinEarningMethod->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (!hash_equals($this->methodEditorVersion($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                    ]);
                }
                $this->ensureUsableDestination($payload, $locked);
                $locked->update($payload);
            }, 3);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'campaign_key' => [$exception->getMessage()],
            ]);
        }

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث طريقة ربح العملات بنجاح');
    }

    public function destroy(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($coinEarningMethod, $validated): void {
            $locked = CoinEarningMethod::query()
                ->whereKey($coinEarningMethod->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (!hash_equals($this->methodEditorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف طريقة ربح العملات بنجاح');
    }

    public function storeRewardRule(
        Request $request,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $payload = $this->rewardRulePayload($request);
        DB::transaction(function () use ($request, $payload, $createIntents): void {
            $rule = RewardRule::create($payload);
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                RewardRule::class,
                $rule->id
            );
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تمت إضافة قاعدة المكافأة وربطها بالحدث.');
    }

    public function updateRewardRule(Request $request, RewardRule $rewardRule)
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        $payload = $this->rewardRulePayload($request, $rewardRule);
        DB::transaction(function () use ($request, $rewardRule, $payload): void {
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                $this->rewardRuleEditorVersion($locked),
                (string) $request->input('editor_version')
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $locked->update($payload);
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قاعدة المكافأة.');
    }

    public function destroyRewardRule(Request $request, RewardRule $rewardRule)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($rewardRule, $validated): void {
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                $this->rewardRuleEditorVersion($locked),
                (string) $validated['editor_version']
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف القاعدة وإيقاف مكافأتها فورًا.');
    }

    private function ensureUsableDestination(array $payload, ?CoinEarningMethod $existing = null): void
    {
        $method = $existing ? clone $existing : new CoinEarningMethod();
        $method->forceFill($payload);
        if (!$method->hasUsableDestination()) {
            throw ValidationException::withMessages([
                'action_url' => [
                    'أضف رابط HTTPS موثوقًا، أو أضف رابط حساب السوشيال المطابق من إعدادات التصميم.',
                ],
            ]);
        }
    }

    private function rewardRulePayload(Request $request, ?RewardRule $existing = null): array
    {
        $eventRule = Rule::in(array_keys(RewardRule::EVENTS));
        $uniqueRule = Rule::unique('reward_rules', 'event_key');
        if ($existing) {
            $uniqueRule->ignore($existing->id);
        }

        $validated = $request->validate([
            'event_key' => ['required', 'string', $eventRule, $uniqueRule],
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'coins_amount' => 'required|integer|min:0|max:1000000',
            'interval_count' => 'required|integer|min:1|max:1440',
            'daily_cap' => 'nullable|integer|min:0|max:1000000',
            'rolling_30_day_cap' => 'nullable|integer|min:0|max:1000000',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
        ]);
        unset($validated['authoring_request_id']);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 100);

        if (
            in_array($validated['event_key'], ['daily_checkin', 'streak_milestone', 'study_session', 'course_completed'], true)
            && empty($validated['rolling_30_day_cap'])
        ) {
            throw ValidationException::withMessages([
                'rolling_30_day_cap' => ['أدخل حدًا خلال 30 يومًا حتى تظل تكلفة المكافأة منضبطة.'],
            ]);
        }

        return $validated;
    }

    private function settingsEditorVersion(?Setting $setting): string
    {
        return hash('sha256', json_encode([
            (string) ($setting?->how_to_use_coins_ar ?? ''),
            (string) ($setting?->how_to_use_coins_en ?? ''),
            (int) ($setting?->reward_balance_cap ?? 1200),
            (int) ($setting?->max_reward_contribution_per_course ?? 1200),
            (string) ($setting?->recommended_social_provider ?? config('social_auth.recommended_provider')),
            (int) ($setting?->recommended_provider_bonus_coins ?? 0),
            (string) ($setting?->recommended_provider_badge_ar ?? ''),
            (string) ($setting?->recommended_provider_badge_en ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function methodEditorVersion(CoinEarningMethod $method): string
    {
        return hash('sha256', json_encode([
            (string) $method->title_ar,
            (string) $method->title_en,
            (int) $method->coins_amount,
            (string) $method->action_key,
            (string) $method->campaign_key,
            (string) $method->action_url,
            (bool) $method->requires_external_visit,
            (int) $method->verification_delay_seconds,
            $method->starts_at?->toIso8601String(),
            $method->ends_at?->toIso8601String(),
            $method->total_claim_limit === null ? null : (int) $method->total_claim_limit,
            (bool) $method->is_active,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function rewardRuleEditorVersion(RewardRule $rule): string
    {
        return hash('sha256', json_encode([
            (string) $rule->event_key,
            (string) $rule->title_ar,
            (string) $rule->title_en,
            (int) $rule->coins_amount,
            (int) $rule->interval_count,
            $rule->daily_cap === null ? null : (int) $rule->daily_cap,
            $rule->rolling_30_day_cap === null ? null : (int) $rule->rolling_30_day_cap,
            (bool) $rule->is_active,
            (int) $rule->sort_order,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
