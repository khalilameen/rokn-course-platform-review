<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\VerifiedStorePurchase;

interface StorePurchaseProviderGateway
{
    public function verify(
        string $provider,
        string $productId,
        string $purchaseToken,
        ?string $transactionId,
        string $expectedAccountBinding
    ): VerifiedStorePurchase;
}
