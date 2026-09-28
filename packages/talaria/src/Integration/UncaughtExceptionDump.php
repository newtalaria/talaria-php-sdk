<?php

declare(strict_types=1);

namespace Talaria\Integration;

/**
 * PHP's engine repeats an escaped exception as an E_ERROR whose message is
 * "Uncaught {Class}: …\nStack trace:\n…\n  thrown". That string is not a new
 * failure. Monolog's exception handler uses a shorter "Uncaught …" line and
 * passes the real Throwable in context.
 */
final class UncaughtExceptionDump
{
    /**
     * True for the engine fatal text, including a "Fatal Error (E_ERROR): " prefix.
     */
    public static function isEngineFatal(string $message): bool
    {
        if (!str_contains($message, 'Stack trace:')) {
            return false;
        }

        if (preg_match('/Uncaught\s+[A-Za-z_\\\\][\w\\\\]*/', $message) !== 1) {
            return false;
        }

        return preg_match('/\bthrown\s*$/', $message) === 1;
    }

    /**
     * Monolog logs uncaught exceptions as "Uncaught Exception {class}: …".
     * That line still carries the real Throwable; the event should be unhandled.
     */
    public static function logMarksUnhandled(string $message): bool
    {
        return str_starts_with($message, 'Uncaught ');
    }
}
