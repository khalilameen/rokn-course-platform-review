<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

final class AdminSessionIdentity
{
    public const SESSION_KEY = 'dashboard_auth_identity';

    public function fingerprint(User $user): string
    {
        $identity = implode('|', [
            (string) $user->getAuthIdentifier(),
            strtolower(trim((string) $user->getRawOriginal('email'))),
            (string) $user->getAuthPassword(),
            strtolower(trim((string) $user->getRawOriginal('role'))),
            (bool) $user->getRawOriginal('active') ? '1' : '0',
        ]);

        return hash_hmac('sha256', $identity, (string) config('app.key'));
    }
}
