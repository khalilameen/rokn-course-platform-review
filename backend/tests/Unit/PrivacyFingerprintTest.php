<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PrivacyFingerprint;
use Tests\TestCase;

final class PrivacyFingerprintTest extends TestCase
{
    public function test_it_is_stable_non_reversible_and_null_safe(): void
    {
        $raw = '201.10.20.30';
        $first = PrivacyFingerprint::make($raw);

        self::assertNull(PrivacyFingerprint::make(null));
        self::assertNull(PrivacyFingerprint::make('  '));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first);
        self::assertSame($first, PrivacyFingerprint::make($raw));
        self::assertNotSame($raw, $first);
        self::assertNotSame($first, PrivacyFingerprint::make('201.10.20.31'));
    }
}
