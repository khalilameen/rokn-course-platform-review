<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BunnyService;
use PHPUnit\Framework\TestCase;

final class BunnyAdvancedTokenTest extends TestCase
{
    public function test_it_matches_a_fixed_hmac_sha256_base64url_vector(): void
    {
        self::assertSame(
            'HS256-vH4aaxTlWZY_4-uPpjwhVD6ryUXM1bAM2PuUroQCqQ4',
            BunnyService::advancedToken(
                'test-key',
                '/videos/abc/playlist.m3u8',
                1700000000
            )
        );
    }

    public function test_signing_data_is_part_of_the_authenticated_payload(): void
    {
        self::assertNotSame(
            BunnyService::advancedToken('test-key', '/videos/abc/', 1700000000),
            BunnyService::advancedToken(
                'test-key',
                '/videos/abc/',
                1700000000,
                'token_path=/videos/abc/'
            )
        );
    }
}
