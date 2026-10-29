<?php

declare(strict_types=1);

namespace Talaria\Transport;

/**
 * RFC 9457 problem+json body from a Talaria /v1 response.
 */
final class IngestError
{
    public function __construct(
        public readonly ?string $className = null,
        public readonly ?string $message = null,
        public readonly ?bool $retry = null,
        public readonly ?string $code = null,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        public readonly ?string $cell = null,
        public readonly ?string $redirectUrl = null,
    ) {
    }

    public static function parse(string $body): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return new self();
        }
        if (!is_array($decoded)) {
            return new self();
        }

        $code = self::stringOf($decoded['code'] ?? null);
        $status = is_int($decoded['status'] ?? null) ? $decoded['status'] : null;
        $detail = self::stringOf($decoded['detail'] ?? $decoded['title'] ?? null);
        $retry = self::retryOf($code, $status);

        return new self(
            message: $detail,
            retry: $retry,
            code: $code,
            status: $status,
            requestId: self::stringOf($decoded['requestId'] ?? null),
            cell: self::stringOf($decoded['cell'] ?? null),
            redirectUrl: self::stringOf($decoded['url'] ?? null),
        );
    }

    public function isPermanent(): bool
    {
        if ($this->retry === true) {
            return false;
        }
        if ($this->code === 'quota_exceeded' || $this->code === 'rate_limited') {
            return false;
        }
        if ($this->status !== null && ($this->status === 429 || $this->status >= 500)) {
            return false;
        }
        if ($this->retry === false || $this->code !== null) {
            return true;
        }
        if ($this->status !== null && $this->status >= 400) {
            return true;
        }

        return false;
    }

    public function isScopeOnly(): bool
    {
        return $this->code === 'insufficient_scope';
    }

    public function isGlobalCredentialFailure(): bool
    {
        return $this->code === 'invalid_key' || $this->code === 'not_found';
    }

    public function disabledSignal(): ?string
    {
        if (!$this->isPermanent() || $this->isGlobalCredentialFailure() || $this->isScopeOnly()) {
            return null;
        }
        $message = strtolower((string) $this->message);
        if (str_contains($message, 'tracing is disabled') || str_contains($message, 'performance is disabled')) {
            return 'spans';
        }
        if (str_contains($message, 'analytics is disabled')) {
            return 'analytics';
        }
        if (str_contains($message, 'session replay is disabled')) {
            return 'replay';
        }

        return null;
    }

    private static function retryOf(?string $code, ?int $status): ?bool
    {
        if ($code === 'quota_exceeded' || $code === 'rate_limited') {
            return true;
        }
        if ($code !== null) {
            return false;
        }
        if ($status === null) {
            return null;
        }
        if ($status === 429 || $status >= 500) {
            return true;
        }
        if ($status >= 400) {
            return false;
        }

        return null;
    }

    private static function stringOf(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
