<?php

namespace App\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Schema;

/**
 * SHA-256-at-rest API tokens with a bounded, zero-downtime migration path.
 *
 * Newly issued tokens are hashed by HasApiTokens. A legacy plaintext row is
 * accepted once and atomically replaced with its hash, so an upgrade does not
 * sign every learner out. Disable the fallback after the longest token lifetime.
 */
final class TransitioningTokenGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        UserProvider $provider,
        private readonly Request $request,
        private readonly bool $allowLegacyPlaintext = true,
        private readonly bool $allowLegacyTransports = false
    ) {
        $this->provider = $provider;
    }

    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $plainToken = $this->tokenForRequest();
        if (!$plainToken) {
            return null;
        }

        $apiToken = $this->findToken($plainToken, true);
        if (!$apiToken) {
            return null;
        }

        $user = $this->provider->retrieveById($apiToken->user_id);
        if (!$user || !(bool) $user->getAuthIdentifier() || !(bool) ($user->active ?? false)) {
            // Never renew a credential for a disabled or soft-deleted account.
            // Removing the presented row also closes the legacy-plaintext bridge.
            $apiToken->delete();
            return null;
        }

        if ($apiToken->shouldExtendLife()) {
            $apiToken->forceFill([
                'expired_at' => now()->addDays((int) config('multiple-tokens-auth.token.life_length', 60)),
            ])->save();
        }

        $this->markSessionActive($apiToken);
        $this->request->attributes->set('rokn_api_token', $apiToken);

        return $this->user = $user;
    }

    public function validate(array $credentials = [])
    {
        $token = (string) ($credentials['token'] ?? '');
        if ($token === '') {
            return false;
        }

        $apiToken = $this->findToken($token, false);
        if (!$apiToken) {
            return false;
        }

        $user = $this->provider->retrieveById($apiToken->user_id);
        if (!$user || !(bool) ($user->active ?? false)) {
            $apiToken->delete();
            return false;
        }

        return true;
    }

    public function logout()
    {
        $plainToken = $this->tokenForRequest();
        if ($plainToken !== '') {
            ApiToken::query()
                ->whereIn('token', array_values(array_unique([
                    hash('sha256', $plainToken),
                    $plainToken,
                ])))
                ->get()
                ->each(fn (ApiToken $token): mixed => $token->revoke());
        }

        $this->user = null;
    }

    private function findToken(string $plainToken, bool $migrateLegacy): ?ApiToken
    {
        $hashed = hash('sha256', $plainToken);
        $apiToken = ApiToken::query()
            ->where('token', $hashed)
            ->whereHasNotExpired()
            ->first();

        if ($apiToken || !$this->allowLegacyPlaintext) {
            return $apiToken;
        }

        $legacy = ApiToken::query()
            ->where('token', $plainToken)
            ->whereHasNotExpired()
            ->first();
        if (!$legacy || !$migrateLegacy) {
            return $legacy;
        }

        try {
            $legacy->forceFill(['token' => $hashed])->save();
        } catch (\Illuminate\Database\QueryException $exception) {
            // A concurrent request may have migrated the same credential.
            $legacy = ApiToken::query()
                ->where('token', $hashed)
                ->whereHasNotExpired()
                ->first();
            if (!$legacy) {
                throw $exception;
            }
        }

        return $legacy;
    }

    private function tokenForRequest(): string
    {
        // Bearer always wins. This prevents a query/body value from shadowing
        // the authenticated mobile session when compatibility is enabled.
        $bearer = trim((string) ($this->request->bearerToken() ?: ''));
        if ($bearer !== '') {
            return $bearer;
        }

        if (!$this->allowLegacyTransports) {
            return '';
        }

        return trim((string) (
            $this->request->query('api_token')
            ?: $this->request->input('api_token')
            ?: $this->request->getPassword()
            ?: ''
        ));
    }

    private function markSessionActive(ApiToken $apiToken): void
    {
        if (!Schema::hasColumn($apiToken->getTable(), 'last_used_at')) {
            return;
        }

        $lastUsedAt = $apiToken->last_used_at;
        if ($lastUsedAt !== null && $lastUsedAt->isAfter(now()->subMinutes(5))) {
            return;
        }

        // A bounded touch avoids turning every API read into a database write
        // while keeping the device/session panel operationally useful.
        ApiToken::query()
            ->whereKey($apiToken->getKey())
            ->where(function ($query): void {
                $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subMinutes(5));
            })
            ->update(['last_used_at' => now()]);
        $apiToken->last_used_at = now();
    }
}
