<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ArabicDisplayText;
use PHPUnit\Framework\TestCase;

final class ArabicDisplayTextTest extends TestCase
{
    public function test_it_localizes_visible_numbers_without_breaking_machine_tokens(): void
    {
        $formatted = ArabicDisplayText::format(
            'لديك 20 عملة افتح https://rokn.app/course/52 بالكود 2zm_64'
        );

        self::assertStringContainsString('٢٠ عملة', $formatted);
        self::assertStringContainsString("\u{2068}https://rokn.app/course/52\u{2069}", $formatted);
        self::assertStringContainsString("\u{2068}2zm_64\u{2069}", $formatted);
    }
}
