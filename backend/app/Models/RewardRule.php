<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RewardRule extends Model
{
    public const EVENTS = [
        'welcome_bonus' => 'أول تسجيل موثّق',
        'daily_checkin' => 'فتح التطبيق يوميًا',
        'streak_milestone' => 'اكتمال مدة استمرارية',
        'study_session' => 'إكمال مدة دراسة مؤهلة',
        'first_project_passed' => 'أول مشروع ناجح بعد المراجعة',
        'course_completed' => 'إنهاء كورس',
    ];

    protected $fillable = [
        'event_key', 'title_ar', 'title_en', 'coins_amount', 'interval_count',
        'daily_cap', 'rolling_30_day_cap', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'coins_amount' => 'integer',
        'interval_count' => 'integer',
        'daily_cap' => 'integer',
        'rolling_30_day_cap' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function activeFor(string $event): ?self
    {
        if (!Schema::hasTable('reward_rules')) {
            return null;
        }

        $load = fn () => static::query()->active()->where('event_key', $event)->first();
        try {
            return Cache::remember('reward-rule:active:v2:' . $event, 30, $load);
        } catch (Throwable) {
            return $load();
        }
    }

    public static function configuredAmount(string $event, int $legacyFallback = 0): int
    {
        if (!Schema::hasTable('reward_rules')) {
            return max(0, $legacyFallback);
        }

        return max(0, (int) (static::activeFor($event)?->coins_amount ?? 0));
    }

    protected static function booted(): void
    {
        $forget = static function (RewardRule $rule): void {
            try {
                Cache::forget('reward-rule:active:v2:' . $rule->event_key);
            } catch (Throwable) {
            }
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
