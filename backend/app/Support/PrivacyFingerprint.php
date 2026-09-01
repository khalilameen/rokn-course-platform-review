<?php

declare(strict_types=1);

namespace App\Support;

final class PrivacyFingerprint
{
    public static function make(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
