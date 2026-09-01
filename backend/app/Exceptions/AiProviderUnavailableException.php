<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AiProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        public readonly bool $retrySafe,
        string $message = 'AI provider is temporarily unavailable.',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
