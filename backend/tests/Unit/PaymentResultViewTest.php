<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class PaymentResultViewTest extends TestCase
{
    public function test_pending_payment_is_not_rendered_as_a_failure(): void
    {
        $html = view('payment.result', [
            'success' => false,
            'pending' => true,
            'order_ref' => 'PKG-PENDING-VIEW',
            'message' => 'جار التحقق من العملية',
        ])->render();

        self::assertStringContainsString('جار تأكيد الدفع', $html);
        self::assertStringContainsString('var PAYMENT_STATUS  = "pending"', $html);
        self::assertStringNotContainsString('<h1>فشلت عملية الدفع</h1>', $html);
        self::assertStringContainsString('window.location.replace(deepLinkUrl)', $html);
    }
}
