<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WhatsAppService;
use Tests\TestCase;

final class WhatsAppVerificationCodeTest extends TestCase
{
    public function test_verification_codes_are_numeric_configured_length_and_random(): void
    {
        config()->set('whatsapp.verification.code_length', 6);

        $codes = collect(range(1, 20))
            ->map(static fn (): string => WhatsAppService::generateCode());

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        }

        self::assertGreaterThan(1, $codes->unique()->count());
    }
}
