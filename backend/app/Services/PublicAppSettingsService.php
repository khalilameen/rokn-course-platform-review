<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\RoknLocale;
use Throwable;

final class PublicAppSettingsService
{
    private const CACHE_KEY_PREFIX = 'public-app-settings:v3:';

    private const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com', 'fb.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'instagram' => ['instagram.com'],
        'tiktok' => ['tiktok.com'],
        'telegram' => ['t.me', 'telegram.me'],
    ];

    public function __construct(private readonly AppReleaseChannelService $releases)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(?string $locale = null): array
    {
        $locale = RoknLocale::normalize($locale) ?? RoknLocale::fromRequest(request());

        $load = function () use ($locale): array {
            $general = Setting::query()->first() ?? new Setting();
            $design = DesignSetting::getDefaultSettings();
            $whatsApp = $this->whatsAppUrl(
                $general->support_whatsapp_url ?: $design->whatsapp_url
            );
            $releaseUrls = $this->releases->urls($general);

            return [
                'contract_version' => 2,
                'revision' => hash('sha256', implode('|', [
                    (string) ($general->updated_at?->getTimestamp() ?? 0),
                    (string) ($design->updated_at?->getTimestamp() ?? 0),
                    (string) ($releaseUrls['play'] ?? ''),
                    (string) ($releaseUrls['appstore'] ?? ''),
                    (string) ($releaseUrls['direct'] ?? ''),
                ])),
                'name' => trim((string) (
                    $locale === 'en'
                        ? ($design->name_en ?: $design->name_ar ?: 'Rokn')
                        : ($design->name_ar ?: $design->name_en ?: 'ركن')
                )),
                'branding' => [
                    'logo_url' => $this->publicMediaUrl($design->logo_url),
                    'icon_url' => $this->publicMediaUrl($design->icon_url),
                    'home_background_url' => $this->publicMediaUrl($design->home_background_url),
                ],
                'social_media' => [
                    'facebook' => $this->socialUrl('facebook', $design->facebook_url),
                    'youtube' => $this->socialUrl('youtube', $design->youtube_url),
                    'instagram' => $this->socialUrl('instagram', $design->instagram_url),
                    'tiktok' => $this->socialUrl('tiktok', $design->tiktok_url),
                    'whatsapp' => $whatsApp,
                    'telegram' => $this->socialUrl('telegram', $design->telegram_url),
                ],
                'support_contacts' => [
                    'email' => filter_var($general->email, FILTER_VALIDATE_EMAIL)
                        ? strtolower(trim((string) $general->email))
                        : null,
                    'phone' => $this->publicPhone($general->phone),
                    'whatsapp' => $whatsApp,
                ],
                'support_whatsapp_url' => $whatsApp,
                'about_url' => route('about'),
                'contact_url' => route('contact'),
                'privacy_url' => route('privacy'),
                'terms_url' => route('terms'),
                // Compatibility for installed clients. This is intentionally
                // not presented as a separate settings item in the current app.
                'returns_policy_url' => route('returns-policy'),
                'account_deletion_url' => route('account-deletion.show'),
                'android_app_url' => $releaseUrls['play'],
                'ios_app_url' => $releaseUrls['appstore'],
                'direct_android_app_url' => $releaseUrls['direct'],
                'about_us_url' => $this->pageUrl($general->about_us_url, route('about')),
                'privacy_policy_url' => $this->pageUrl($general->privacy_policy_url, route('privacy')),
                // Additive legacy fields for APKs already in circulation.
                'policy_content' => trim((string) (
                    $locale === 'en'
                        ? ($design->policy_content_en ?: $design->policy_content_ar)
                        : ($design->policy_content_ar ?: $design->policy_content_en)
                )) ?: null,
                'coin_rules' => $general->how_to_use_coins,
            ];
        };

        try {
            return Cache::remember(self::CACHE_KEY_PREFIX.$locale, now()->addMinutes(5), $load);
        } catch (Throwable) {
            return $load();
        }
    }

    public static function invalidate(): void
    {
        $forget = static function (): bool {
            $arabic = Cache::forget(self::CACHE_KEY_PREFIX.'ar');
            $english = Cache::forget(self::CACHE_KEY_PREFIX.'en');
            return $arabic || $english;
        };
        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($forget);
                return;
            }
            $forget();
        } catch (Throwable) {
            // Cache invalidation must never turn a successful settings write
            // into a failed dashboard action. The entry also has a short TTL.
        }
    }

    public function socialUrl(string $channel, mixed $value): ?string
    {
        return $this->allowedHttpsUrl($value, self::SOCIAL_HOSTS[$channel] ?? []);
    }

    public function whatsAppUrl(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $parts = filter_var($raw, FILTER_VALIDATE_URL) ? parse_url($raw) : false;
        if (is_array($parts)) {
            if (
                strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !in_array(strtolower((string) ($parts['host'] ?? '')), ['wa.me', 'www.wa.me'], true)
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return null;
            }
            $digits = trim((string) ($parts['path'] ?? ''), '/');
        } else {
            $digits = preg_replace('/[\s()+.\-]+/', '', $raw) ?? '';
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1
            ? 'https://wa.me/'.$digits
            : null;
    }

    public function embedVideoUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? ''))) ?? '';
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            if ($path === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = $query['v'] ?? null;
            } elseif (str_starts_with($path, 'embed/')) {
                $videoId = explode('/', substr($path, 6))[0] ?? null;
            }
        } elseif ($host === 'youtube-nocookie.com' && str_starts_with($path, 'embed/')) {
            $videoId = explode('/', substr($path, 6))[0] ?? null;
        }

        if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$videoId;
        }

        $vimeoId = null;
        if ($host === 'vimeo.com') {
            $vimeoId = explode('/', $path)[0] ?? null;
        } elseif ($host === 'player.vimeo.com' && str_starts_with($path, 'video/')) {
            $vimeoId = explode('/', substr($path, 6))[0] ?? null;
        }

        return is_string($vimeoId) && preg_match('/^[0-9]{5,15}$/', $vimeoId) === 1
            ? 'https://player.vimeo.com/video/'.$vimeoId
            : null;
    }

    private function pageUrl(mixed $value, string $fallback): string
    {
        return $this->allowedHttpsUrl($value) ?? $fallback;
    }

    /** @param list<string> $allowedRoots */
    private function allowedHttpsUrl(mixed $value, array $allowedRoots = []): ?string
    {
        $url = trim((string) $value);
        $parts = $url !== '' ? parse_url($url) : false;
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        if ($allowedRoots !== []) {
            $trusted = false;
            foreach ($allowedRoots as $root) {
                if ($host === $root || str_ends_with($host, '.'.$root)) {
                    $trusted = true;
                    break;
                }
            }
            if (!$trusted) {
                return null;
            }
        }

        return $url;
    }

    private function publicMediaUrl(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if (str_starts_with($raw, '/') && !str_starts_with($raw, '//')) {
            $raw = rtrim((string) config('app.url'), '/').'/'.ltrim($raw, '/');
        }
        $url = $this->allowedHttpsUrl($raw);
        if ($url === null) {
            return null;
        }
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $mediaHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        $normaliseHost = static fn (string $host): string => preg_replace('/^www\./', '', $host) ?? $host;

        return $appHost !== '' && $normaliseHost($mediaHost) === $normaliseHost($appHost)
            ? $url
            : null;
    }

    private function publicPhone(mixed $value): ?string
    {
        $phone = trim((string) $value);
        return preg_match('/^\+?[0-9][0-9\s().-]{6,24}$/', $phone) === 1 ? $phone : null;
    }
}
