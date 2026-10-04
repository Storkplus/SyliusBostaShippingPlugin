<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Api;

final class BostaApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?int $errorCode = null,
        private readonly bool $transient = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /** @param array<array-key, mixed> $body */
    public static function fromResponse(int $statusCode, array $body): self
    {
        $message = $body['message'] ?? null;
        if (is_array($message)) {
            $message = implode('; ', array_filter($message, 'is_string'));
        }
        if (!is_string($message) || '' === $message) {
            $message = sprintf('Bosta responded with HTTP %d.', $statusCode);
        }

        $errorCode = $body['errorCode'] ?? null;

        return new self(
            $message,
            $statusCode,
            is_numeric($errorCode) ? (int) $errorCode : null,
            $statusCode >= 500 || 429 === $statusCode,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }

    /** Timeouts, 5xx and rate limits: the request may or may not have been applied. */
    public function isTransient(): bool
    {
        return $this->transient;
    }

    public function describe(): string
    {
        $parts = [$this->getMessage()];
        if (null !== $this->errorCode) {
            $parts[] = sprintf('code %d', $this->errorCode);
        }
        if ($this->statusCode > 0) {
            $parts[] = sprintf('HTTP %d', $this->statusCode);
        }

        return implode(' · ', $parts);
    }
}
