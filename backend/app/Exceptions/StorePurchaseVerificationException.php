<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class StorePurchaseVerificationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'تعذّر التحقق من عملية الشراء.',
        public readonly int $httpStatus = 422
    ) {
        parent::__construct($message);
    }
}
