<?php

declare(strict_types=1);

namespace Talaria\Transport;

/**
 * Process-wide backoff for `ingest_blocked` (IB3). While paused, flush is
 * skipped. One warning is logged. The pause is not persisted across a restart.
 */
final class IngestBlockGate
{
    public const MIN_RETRY_SECONDS = 15 * 60;
    public const MAX_RETRY_SECONDS = 24 * 60 * 60;

    private int $until = 0;
    private ?string $reason = null;
    private bool $warned = false;

    /**
     * @param callable(): int $clock Unix time in seconds
     * @param (callable(string): void)|null $warn
     */
    public function __construct(
        private $clock = null,
        private $warn = null,
    ) {
        $this->clock ??= static fn (): int => time();
    }

    public static function clampRetryAfter(?int $seconds): int
    {
        $value = $seconds ?? self::MIN_RETRY_SECONDS;

        return max(self::MIN_RETRY_SECONDS, min(self::MAX_RETRY_SECONDS, $value));
    }

    public function isBlocked(): bool
    {
        return ($this->clock)() < $this->until;
    }

    public function reason(): ?string
    {
        return $this->isBlocked() ? $this->reason : null;
    }

    public function remainingSeconds(): int
    {
        if (!$this->isBlocked()) {
            return 0;
        }

        return $this->until - ($this->clock)();
    }

    public function applyFromError(IngestError $error): bool
    {
        if (!$error->isIngestBlocked()) {
            return false;
        }
        $this->pause($error->retryAfterSeconds, $error->reason);

        return true;
    }

    /**
     * @param mixed $raw
     */
    public function applyFromDocument(mixed $raw): void
    {
        if (!is_array($raw) || !isset($raw['state']) || !is_string($raw['state'])) {
            return;
        }
        if ($raw['state'] === 'ok') {
            $this->clear();

            return;
        }
        if ($raw['state'] !== 'blocked') {
            return;
        }
        $reason = isset($raw['reason']) && is_string($raw['reason']) ? $raw['reason'] : null;
        $retry = isset($raw['retryAfterSeconds']) && is_numeric($raw['retryAfterSeconds'])
            ? (int) $raw['retryAfterSeconds']
            : null;
        $this->pause($retry, $reason);
    }

    public function pause(?int $seconds, ?string $reason): void
    {
        $until = ($this->clock)() + self::clampRetryAfter($seconds);
        if ($until < $this->until) {
            return;
        }
        $this->until = $until;
        $this->reason = $reason;
        $this->warnOnce();
    }

    public function clear(): void
    {
        $this->until = 0;
        $this->reason = null;
    }

    private function warnOnce(): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;
        $reason = $this->reason !== null && $this->reason !== '' ? " ({$this->reason})" : '';
        $message = "[Talaria] ingest is stopped{$reason}. The SDK will check again in {$this->remainingSeconds()}s and will not send in between. The app keeps running.";
        if ($this->warn !== null) {
            try {
                ($this->warn)($message);

                return;
            } catch (\Throwable) {
                // Fall through.
            }
        }
        error_log($message);
    }
}
