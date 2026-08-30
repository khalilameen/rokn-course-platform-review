<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

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

        return static::query()->active()->where('event_key', $event)->first();
    }

    public static function configuredAmount(string $event, int $legacyFallback = 0): int
    {
        if (!Schema::hasTable('reward_rules')) {
            return max(0, $legacyFallback);
        }

        return max(0, (int) (static::activeFor($event)?->coins_amount ?? 0));
    }
}
