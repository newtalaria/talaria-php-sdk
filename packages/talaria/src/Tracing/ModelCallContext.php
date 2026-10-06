<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * Model, operation, provider, and HTTP status attached to a thrown model call.
 *
 * Capture copies this onto the event. The server fingerprints from those fields.
 *
 * @phpstan-type Stamp array{model?: string, operation: string, provider: string, statusCode?: int}
 */
final class ModelCallContext
{
    /** @var \WeakMap<\Throwable, Stamp>|null */
    private static ?\WeakMap $stamps = null;

    /**
     * @param Stamp $stamp
     */
    public static function stamp(\Throwable $error, array $stamp): void
    {
        self::map()->offsetSet($error, $stamp);
    }

    /**
     * @return Stamp|null
     */
    public static function read(\Throwable $error): ?array
    {
        $map = self::map();
        if ($map->offsetExists($error)) {
            /** @var Stamp $stamp */
            $stamp = $map->offsetGet($error);

            return $stamp;
        }
        $previous = $error->getPrevious();
        if ($previous !== null && $map->offsetExists($previous)) {
            /** @var Stamp $stamp */
            $stamp = $map->offsetGet($previous);

            return $stamp;
        }

        return null;
    }

    /**
     * @return \WeakMap<\Throwable, Stamp>
     */
    private static function map(): \WeakMap
    {
        return self::$stamps ??= new \WeakMap();
    }
}
