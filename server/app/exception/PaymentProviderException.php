<?php

namespace app\exception;

use RuntimeException;

class PaymentProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private bool $transientFailure,
        private string $providerCode = '',
        private int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isTransient(): bool { return $this->transientFailure; }
    public function providerCode(): string { return $this->providerCode; }
    public function httpStatus(): int { return $this->httpStatus; }
}
