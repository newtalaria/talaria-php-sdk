<?php

declare(strict_types=1);

namespace Talaria;

use Talaria\Context\RuntimeContext;

/**
 * Process-local identity for events, spans, and analytics.
 *
 * PHP has no cookie jar — callers pass {@see $userId} and/or {@see $anonymousId}
 * (browser ids forwarded from checkout APIs). {@see $sessionId} is generated
 * per client unless overridden.
 */
final class Identity
{
    public function __construct(
        public ?string $userId = null,
        public ?string $anonymousId = null,
        public string $sessionId = '',
    ) {
        $this->userId = self::nullable($userId);
        $this->anonymousId = self::nullable($anonymousId);
        $this->sessionId = $sessionId !== '' ? $sessionId : RuntimeContext::newSessionId();
    }

    public function setUser(?string $userId): void
    {
        $this->userId = self::nullable($userId);
    }

    public function setAnonymousId(?string $anonymousId): void
    {
        $this->anonymousId = self::nullable($anonymousId);
    }

    public function setSessionId(string $sessionId): void
    {
        if ($sessionId !== '') {
            $this->sessionId = $sessionId;
        }
    }

    public function hasAnalyticsIdentity(): bool
    {
        return $this->userId !== null || $this->anonymousId !== null;
    }

    /**
     * Wire `anonymousId` is required by analytics ingest. Prefer an explicit
     * visitor id; fall back to `userId` when the caller only identified a user.
     */
    public function wireAnonymousId(?string $override = null): ?string
    {
        $override = self::nullable($override);
        if ($override !== null) {
            return $override;
        }
        if ($this->anonymousId !== null) {
            return $this->anonymousId;
        }

        return $this->userId;
    }

    public function wireUserId(?string $override = null): ?string
    {
        $override = self::nullable($override);

        return $override ?? $this->userId;
    }

    public function wireSessionId(?string $override = null): string
    {
        $override = self::nullable($override);

        return $override ?? $this->sessionId;
    }

    /**
     * Clear identified user + visitor id and start a new in-memory session.
     * Does not write cookies.
     */
    public function resetAnalytics(): void
    {
        $this->userId = null;
        $this->anonymousId = null;
        $this->sessionId = RuntimeContext::newSessionId();
    }

    public function restore(?string $userId, ?string $anonymousId, ?string $sessionId): void
    {
        $this->userId = self::nullable($userId);
        $this->anonymousId = self::nullable($anonymousId);
        $this->sessionId = $sessionId !== null && $sessionId !== ''
            ? $sessionId
            : RuntimeContext::newSessionId();
    }

    private static function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
