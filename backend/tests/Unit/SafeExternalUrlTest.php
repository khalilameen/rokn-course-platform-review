<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SafeExternalUrl;
use PHPUnit\Framework\TestCase;

final class SafeExternalUrlTest extends TestCase
{
    public function test_it_accepts_only_normal_https_links_without_embedded_credentials(): void
    {
        self::assertSame(
            'https://example.com/work?view=1#result',
            SafeExternalUrl::sanitize('  https://example.com/work?view=1#result  ')
        );

        foreach ([
            'http://example.com',
            'ftp://example.com/file',
            'file://example.com/share',
            'data://example.com/payload',
            'https://user:pass@example.com/private',
            'https:///missing-host',
            '',
            null,
            123,
        ] as $unsafe) {
            self::assertNull(SafeExternalUrl::sanitize($unsafe));
        }
    }
}
