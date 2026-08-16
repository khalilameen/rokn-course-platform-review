<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait HasApiTokens
{
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id');
    }

    public function generateApiToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $plainToken = bin2hex(random_bytes(40));
            $storedToken = (bool) config('multiple-tokens-auth.hash', true)
                ? hash('sha256', $plainToken)
                : $plainToken;

            try {
                $attributes = [
                    'token' => $storedToken,
                    'expired_at' => now()->addDays(
                        (int) config('multiple-tokens-auth.token.life_length', 60)
                    ),
                ];
                if (Schema::hasColumn((string) config('multiple-tokens-auth.table', 'api_tokens'), 'issued_at')) {
                    $attributes['issued_at'] = now();
                }
                $table = (string) config('multiple-tokens-auth.table', 'api_tokens');
                if (Schema::hasColumn($table, 'session_id')) {
                    $attributes['session_id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn($table, 'platform')) {
                    $platform = strtolower($this->safeSessionHeader(
                        'X-Rokn-Platform',
                        (string) request()->input('device_os', request()->input('device_type', 'other')),
                        16
                    ));
                    $attributes['platform'] = in_array($platform, ['android', 'ios', 'web'], true)
                        ? $platform
                        : 'other';
                }
                if (Schema::hasColumn($table, 'app_version')) {
                    $attributes['app_version'] = $this->safeSessionHeader('X-Rokn-App-Version', '', 32) ?: null;
                }
                if (Schema::hasColumn($table, 'app_build')) {
                    $attributes['app_build'] = $this->safeSessionHeader('X-Rokn-App-Build', '', 16) ?: null;
                }
                if (Schema::hasColumn($table, 'last_used_at')) {
                    $attributes['last_used_at'] = now();
                }
                $this->apiTokens()->create($attributes);

                return $plainToken;
            } catch (QueryException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to issue a unique API token.');
    }

    public function purgeApiTokens(): void
    {
        $this->apiTokens()->delete();
    }

    private function safeSessionHeader(string $name, string $fallback, int $maxLength): string
    {
        $value = trim((string) request()->header($name, $fallback));
        // Session metadata is deliberately coarse and excludes hardware IDs,
        // advertising IDs, names, IP addresses and raw user-agent strings.
        $value = preg_replace('/[^0-9A-Za-z._-]/', '', $value) ?? '';

        return mb_substr($value, 0, $maxLength);
    }
}
