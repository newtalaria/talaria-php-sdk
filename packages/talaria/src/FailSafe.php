<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Fail-safe diagnostics. Talaria never throws into the host app: a bad key or
 * init option disables the client, and transport faults are logged, rate-limited.
 */
final class FailSafe
{
    /** Seconds between two transport error lines for the same signal. */
    public const TRANSPORT_LOG_INTERVAL_SECONDS = 60;

    private static bool $disabledWarned = false;

    /** @var array<string, int> */
    private static array $lastTransportLog = [];

    /**
     * At most the first 8 characters of a key, for log lines.
     */
    public static function keyPreview(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return strlen($key) > 8 ? substr($key, 0, 8) . '…' : substr($key, 0, 8);
    }

    /**
     * Log the "Talaria is disabled" warning once per process.
     *
     * @param (callable(string): void)|null $sink Framework logger; error_log when null or when it throws.
     */
    public static function warnDisabledOnce(string $message, ?callable $sink = null): void
    {
        if (self::$disabledWarned) {
            return;
        }
        self::$disabledWarned = true;
        if ($sink !== null) {
            try {
                $sink($message);

                return;
            } catch (\Throwable) {
                // Fall through to error_log.
            }
        }
        error_log($message);
    }

    /**
     * Log a transport or internal fault at most once per interval per channel.
     */
    public static function logRateLimited(string $channel, string $message, ?int $now = null): void
    {
        $now ??= time();
        $last = self::$lastTransportLog[$channel] ?? null;
        if ($last !== null && ($now - $last) < self::TRANSPORT_LOG_INTERVAL_SECONDS) {
            return;
        }
        self::$lastTransportLog[$channel] = $now;
        error_log('[Talaria] ' . $message);
    }

    /**
     * @internal Reset between tests.
     */
    public static function reset(): void
    {
        self::$disabledWarned = false;
        self::$lastTransportLog = [];
    }
}
