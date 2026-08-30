<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinEarningMethod extends Model
{
    private const TRUSTED_ACTION_HOSTS = [
        'instagram.com',
        'tiktok.com',
        'facebook.com',
        'fb.com',
        'youtube.com',
        'youtu.be',
        'rokn.app',
        'rokn.com',
    ];

    protected $fillable = [
        'title_ar',
        'title_en',
        'coins_amount',
        'action_key',
        'action_url',
        'requires_external_visit',
        'verification_delay_seconds',
        'is_active',
        'is_repeatable',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_repeatable' => 'boolean',
        'requires_external_visit' => 'boolean',
        'verification_delay_seconds' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function userEarnings()
    {
        return $this->hasMany(UserCoinEarning::class, 'coin_earning_method_id');
    }

    public function attempts()
    {
        return $this->hasMany(UserCoinTaskAttempt::class);
    }

    public function resolvedActionUrl(): ?string
    {
        $url = trim((string) $this->action_url);
        if ($url === '') {
            $channel = $this->socialChannel();
            if ($channel !== null) {
                $url = trim((string) (DesignSetting::query()->value("{$channel}_url") ?? ''));
            }
        }

        return self::isTrustedActionUrl($url) ? $url : null;
    }

    public function hasUsableDestination(): bool
    {
        return !$this->requires_external_visit || $this->resolvedActionUrl() !== null;
    }

    public static function isTrustedActionUrl(?string $value): bool
    {
        $parts = parse_url(trim((string) $value));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::TRUSTED_ACTION_HOSTS as $root) {
            if ($host === $root || str_ends_with($host, ".{$root}")) {
                return true;
            }
        }

        return false;
    }

    private function socialChannel(): ?string
    {
        $key = strtolower(trim((string) $this->action_key));
        foreach (['instagram', 'tiktok', 'facebook', 'youtube'] as $channel) {
            if ($key === $channel || str_contains($key, $channel)) {
                return $channel;
            }
        }

        return null;
    }
}
