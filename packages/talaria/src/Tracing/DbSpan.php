<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * Shared CLIENT span + query breadcrumb for PDO / MySQLi wrappers.
 */
final class DbSpan
{
    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function trace(
        TalariaClient $client,
        string $system,
        string $sql,
        callable $run,
    ): mixed {
        return $client->recordQuery($sql, $system, $run);
    }
}
