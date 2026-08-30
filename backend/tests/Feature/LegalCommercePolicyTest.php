<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class LegalCommercePolicyTest extends TestCase
{
    public function test_arabic_terms_and_refund_policy_define_closed_loop_final_coin_purchases(): void
    {
        $terms = json_encode(
            require resource_path('lang/ar/terms.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $returns = json_encode(
            require resource_path('lang/ar/returns.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString('شراء العملات نهائي بعد تأكيد الدفع', $terms);
        self::assertStringContainsString('لا يجوز بيعه أو استبداله أو سحبه', $returns);
        self::assertStringContainsString('يبدأ الطلب حصريًا عبر دعم ركن', $terms);
        self::assertStringContainsString('وسيلة الدفع الأصلية فقط', $returns);
    }

    public function test_english_policy_keeps_mandatory_rights_and_provider_reversals_operational(): void
    {
        $terms = json_encode(
            require resource_path('lang/en/terms.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $returns = json_encode(
            require resource_path('lang/en/returns.php'),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        self::assertStringContainsString('A coin purchase is final once payment is confirmed', $terms);
        self::assertStringContainsString('does not limit any right that cannot legally be waived', $terms);
        self::assertStringContainsString('transaction reversed by a bank or store is reconciled', $returns);
    }
}
