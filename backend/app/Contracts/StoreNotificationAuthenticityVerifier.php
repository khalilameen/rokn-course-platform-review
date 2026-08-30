<?php

declare(strict_types=1);

namespace App\Contracts;

interface StoreNotificationAuthenticityVerifier
{
    /** @return array<string, mixed> */
    public function verifyGooglePushToken(string $token): array;

    /** @return array<string, mixed> */
    public function verifyAppleSignedPayload(string $signedPayload): array;
}
