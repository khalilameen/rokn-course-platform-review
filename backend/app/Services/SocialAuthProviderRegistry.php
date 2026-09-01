<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;

final class SocialAuthProviderRegistry
{
    /** @var array<string, string> */
    private const LABELS = [
        'google' => 'Google',
        'tiktok' => 'TikTok',
        'apple' => 'Apple',
        'facebook' => 'Facebook',
    ];

    /** @return Collection<int, string> */
    public function declared(): Collection
    {
        return collect(config('social_auth.providers', []))
            ->map(static fn ($provider): string => strtolower(trim((string) $provider)))
            ->filter(static fn (string $provider): bool => isset(self::LABELS[$provider]))
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    public function available(): Collection
    {
        return $this->declared()
            ->filter(fn (string $provider): bool => $this->isReady($provider))
            ->values();
    }

    public function isReady(string $provider): bool
    {
        return match ($provider) {
            'google' => filled(config('services.google.client_id'))
                && filled(config('services.google.client_secret')),
            'facebook' => filled(config('services.facebook.client_id'))
                && filled(config('services.facebook.client_secret'))
                && preg_match('/\Av\d+\.\d+\z/', trim((string) config('services.facebook.graph_version'))) === 1
                && trim((string) config('services.facebook.graph_version')) !== 'v19.0',
            'tiktok' => filled(config('services.tiktok.client_key'))
                && filled(config('services.tiktok.client_secret')),
            'apple' => $this->appleClientIdsAreValid(),
            default => false,
        };
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->declared()
            ->mapWithKeys(static fn (string $provider): array => [$provider => self::LABELS[$provider]])
            ->all();
    }

    public function reason(string $provider): string
    {
        if ($this->isReady($provider)) {
            return match ($provider) {
                'google' => 'Google OAuth مضبوط',
                'facebook' => 'Facebook OAuth مضبوط على '.trim((string) config('services.facebook.graph_version')),
                'tiktok' => 'TikTok OAuth مضبوط',
                'apple' => 'Apple Sign in audiences مضبوطة',
                default => 'المزوّد مضبوط',
            };
        }

        return match ($provider) {
            'google' => 'GOOGLE_CLIENT_ID أو GOOGLE_CLIENT_SECRET ناقص',
            'facebook' => 'FACEBOOK_CLIENT_ID أو FACEBOOK_CLIENT_SECRET أو FACEBOOK_GRAPH_VERSION ناقص أو غير صالح',
            'tiktok' => 'TIKTOK_CLIENT_KEY أو TIKTOK_CLIENT_SECRET ناقص',
            'apple' => 'APPLE_CLIENT_ID ناقص أو غير صالح',
            default => 'مزوّد غير مدعوم',
        };
    }

    private function appleClientIdsAreValid(): bool
    {
        $clientIds = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.apple.client_id'))
        )));

        return $clientIds !== []
            && collect($clientIds)->every(
                static fn (string $id): bool => preg_match('/\A(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $id) === 1
            );
    }
}
