<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
class Setting extends Model
{
    private const DEFAULT_COIN_RULES_AR = 'عملات ركن رصيد افتراضي غير قابل للتحويل إلى نقد. لديك عملات مكافآت وعملات مشتراة؛ عند فتح كورس تُستخدم المكافآت أولًا ثم العملات المشتراة، وعند الاسترداد تعود العملات إلى مصدرها الأصلي قدر الإمكان.';
    private const DEFAULT_COIN_RULES_EN = 'Rokn coins are a non-withdrawable virtual balance. Reward coins are spent before purchased coins, and refunds return coins to their original source where possible.';

    protected $fillable = [
        'site_name_ar',
        'site_name_en',
        'email',
        'phone',
        'currency_code',
        'seo_meta_title_ar',
        'seo_meta_description_ar',
        'seo_meta_title_en',
        'seo_meta_description_en',
        'google_maps_key',
        'contact',
        'english_translation',
        'device_login_policy',
        'enforce_course_section_order',
        'bunny_enabled',
        'bunny_library_id',
        'bunny_cdn_hostname',
        'bunny_storage_zone_name',
        'bunny_api_key_secret',
        'bunny_storage_password_secret',
        'bunny_security_key_secret',
        'android_app_url',
        'ios_app_url',
        'about_us_url',
        'privacy_policy_url',
        'support_whatsapp_url',
        'how_to_use_coins_ar',
        'how_to_use_coins_en',
        'welcome_bonus_coins',
        'recommended_social_provider',
        'recommended_provider_bonus_coins',
        'recommended_provider_badge_ar',
        'recommended_provider_badge_en',
        'reward_balance_cap',
        'max_reward_contribution_per_course',
        'daily_reward_coins',
        'daily_reward_rolling_30_day_cap',
        'streak_reward_days',
        'streak_reward_coins',
        'streak_reward_rolling_30_day_cap',
        'openrouter_usd_to_egp_rate',
        'study_reward_coins',
        'study_reward_minutes',
        'study_reward_daily_cap',
        'study_reward_rolling_30_day_cap',
        'first_project_reward_coins',
        'course_completion_reward_coins',
        'course_completion_rolling_30_day_cap',
        'ai_daily_user_limit',
        'ai_global_daily_request_limit',
        'ai_global_daily_token_budget',
        'ai_global_monthly_token_budget',
        'ai_answer_cache_minutes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'english_translation' => 'boolean',
        'enforce_course_section_order' => 'boolean',
        'bunny_enabled' => 'boolean',
        'bunny_api_key_secret' => 'encrypted',
        'bunny_storage_password_secret' => 'encrypted',
        'bunny_security_key_secret' => 'encrypted',
        'welcome_bonus_coins' => 'integer',
        'recommended_provider_bonus_coins' => 'integer',
        'reward_balance_cap' => 'integer',
        'max_reward_contribution_per_course' => 'integer',
        'daily_reward_coins' => 'integer',
        'daily_reward_rolling_30_day_cap' => 'integer',
        'streak_reward_days' => 'integer',
        'streak_reward_coins' => 'integer',
        'streak_reward_rolling_30_day_cap' => 'integer',
        'openrouter_usd_to_egp_rate' => 'decimal:4',
        'study_reward_coins' => 'integer',
        'study_reward_minutes' => 'integer',
        'study_reward_daily_cap' => 'integer',
        'study_reward_rolling_30_day_cap' => 'integer',
        'first_project_reward_coins' => 'integer',
        'course_completion_reward_coins' => 'integer',
        'course_completion_rolling_30_day_cap' => 'integer',
        'ai_daily_user_limit' => 'integer',
        'ai_global_daily_request_limit' => 'integer',
        'ai_global_daily_token_budget' => 'integer',
        'ai_global_monthly_token_budget' => 'integer',
        'ai_answer_cache_minutes' => 'integer',
    ];

    protected $hidden = [
        'bunny_api_key',
        'bunny_storage_password',
        'bunny_api_key_secret',
        'bunny_storage_password_secret',
        'bunny_security_key_secret',
    ];

    /**
     * Legacy plaintext credentials are migration inputs only. Returning null
     * prevents any runtime service from silently falling back to them while
     * the migration command can still inspect getRawOriginal().
     */
    public function getBunnyApiKeyAttribute(): ?string
    {
        return null;
    }

    public function getBunnyStoragePasswordAttribute(): ?string
    {
        return null;
    }

    /**
     * Returns how_to_use_coins in the request locale language.
     */
    public function getHowToUseCoinsAttribute(): ?string
    {
        $lang = request()->header('Accept-Language', 'ar');
        $arabic = trim((string) ($this->attributes['how_to_use_coins_ar'] ?? ''));
        $english = trim((string) ($this->attributes['how_to_use_coins_en'] ?? ''));

        if (str_starts_with(strtolower($lang), 'en')) {
            return $english !== '' ? $english : ($arabic !== '' ? $arabic : self::DEFAULT_COIN_RULES_EN);
        }

        return $arabic !== '' ? $arabic : ($english !== '' ? $english : self::DEFAULT_COIN_RULES_AR);
    }

    /**
     * Check if Bunny.net integration is enabled and configured
     *
     * @return bool
     */
    public function isBunnyConfigured(): bool
    {
        return $this->bunny_enabled 
            && !empty(config('bunny.stream_api_key') ?: $this->bunny_api_key_secret)
            && !empty(config('bunny.library_id') ?: $this->bunny_library_id);
    }
}
