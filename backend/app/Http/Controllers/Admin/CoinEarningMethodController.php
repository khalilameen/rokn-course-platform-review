<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\RewardRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\Services\SocialAuthProviderRegistry;

class CoinEarningMethodController extends Controller
{
    public function index(SocialAuthProviderRegistry $socialProviders)
    {
        $methods  = CoinEarningMethod::latest()->paginate(10);
        $setting = Setting::first();
        $rewardRules = RewardRule::query()->orderBy('sort_order')->orderBy('id')->get();
        $rewardEvents = RewardRule::EVENTS;
        $socialProviderLabels = $socialProviders->labels();
        return view('admin.coin_earning_methods.index', compact(
            'methods', 'setting', 'rewardRules', 'rewardEvents', 'socialProviderLabels'
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
        ]);

        Setting::firstOrCreate([])->update($validated);
        Cache::forget('home:general-settings:v1');

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قواعد ومكافآت العملات بنجاح');
    }

    public function create()
    {
        return view('admin.coin_earning_methods.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255|unique:coin_earning_methods,action_key',
            'action_url' => 'nullable|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean',
        ]);

        $payload = $request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'requires_external_visit', 'verification_delay_seconds', 'is_active',
        ]) + ['is_repeatable' => false];
        $this->ensureUsableDestination($payload);
        CoinEarningMethod::create($payload);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم إضافة طريقة ربح العملات بنجاح');
    }

    public function edit(CoinEarningMethod $coinEarningMethod)
    {
        return view('admin.coin_earning_methods.edit', compact('coinEarningMethod'));
    }

    public function update(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255|unique:coin_earning_methods,action_key,' . $coinEarningMethod->id,
            'action_url' => 'nullable|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean',
        ]);

        $payload = $request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'requires_external_visit', 'verification_delay_seconds', 'is_active',
        ]) + ['is_repeatable' => false];
        $this->ensureUsableDestination($payload, $coinEarningMethod);
        $coinEarningMethod->update($payload);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث طريقة ربح العملات بنجاح');
    }

    public function destroy(CoinEarningMethod $coinEarningMethod)
    {
        $coinEarningMethod->delete();

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف طريقة ربح العملات بنجاح');
    }

    public function toggleStatus(CoinEarningMethod $coinEarningMethod)
    {
        if (!$coinEarningMethod->is_active && !$coinEarningMethod->hasUsableDestination()) {
            return response()->json([
                'status' => false,
                'message' => 'أضف رابط المهمة أو رابط حساب السوشيال من إعدادات التصميم أولًا.',
            ], 422);
        }
        $coinEarningMethod->update(['is_active' => !$coinEarningMethod->is_active]);
        return response()->json(['status' => true, 'is_active' => $coinEarningMethod->is_active]);
    }

    public function storeRewardRule(Request $request)
    {
        RewardRule::create($this->rewardRulePayload($request));

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تمت إضافة قاعدة المكافأة وربطها بالحدث.');
    }

    public function updateRewardRule(Request $request, RewardRule $rewardRule)
    {
        $rewardRule->update($this->rewardRulePayload($request, $rewardRule));

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قاعدة المكافأة.');
    }

    public function destroyRewardRule(RewardRule $rewardRule)
    {
        $rewardRule->delete();

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
        ]);
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
}
