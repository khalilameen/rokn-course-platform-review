<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RoknLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoknLocaleTest extends TestCase
{
    #[DataProvider('supportedTags')]
    public function test_it_normalizes_only_supported_language_tags(string $input, string $expected): void
    {
        self::assertSame($expected, RoknLocale::normalize($input));
    }

    public static function supportedTags(): array
    {
        return [
            'Arabic region' => ['ar-EG,ar;q=0.9,en;q=0.8', 'ar'],
            'English region' => ['en_US', 'en'],
            'Weighted fallback' => ['fr-FR, en;q=0.8', 'en'],
            'Rejected preference' => ['ar;q=0, en;q=1', 'en'],
        ];
    }

    public function test_it_rejects_arbitrary_locale_values(): void
    {
        self::assertNull(RoknLocale::normalize('dashboard'));
        self::assertNull(RoknLocale::normalize('../en'));
    }
}
