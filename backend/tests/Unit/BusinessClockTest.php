<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class BusinessClockTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_visible_instants_use_cairo_and_arabic_digits(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('app.business_timezone', 'Africa/Cairo');
        app()->setLocale('ar');

        self::assertSame(
            '٢٠٢٦-٠٩-٠١ ٠١:٣٠',
            BusinessClock::display('2026-08-31T22:30:00Z')
        );
    }

    public function test_relative_time_uses_cairo_calendar_boundaries(): void
    {
        config()->set('app.business_timezone', 'Africa/Cairo');
        app()->setLocale('ar');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01T10:00:00+03:00'));

        self::assertSame('أمس', BusinessClock::relative('2026-08-31T20:30:00Z'));
        self::assertSame('منذ دقيقتين', BusinessClock::relative('2026-09-01T06:58:00Z'));
    }
}
