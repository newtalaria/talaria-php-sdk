<?php

declare(strict_types=1);

namespace Talaria\Laravel\Facade;

use Illuminate\Support\Facades\Facade;
use Talaria\TalariaClient;

/**
 * @method static void captureException(\Throwable $exception, array $context = [])
 * @method static void captureMessage(string $message, mixed $level = 'info', array $context = [])
 * @method static void addBreadcrumb(array $breadcrumb)
 * @method static \Talaria\Tracing\Span startTransaction(string $name, mixed $kind = 'server', array $attributes = [])
 * @method static \Talaria\Tracing\Span startSpan(string $name, mixed $kind = 'internal', array $attributes = [])
 * @method static string|null getTraceparent()
 * @method static void resetRequestState()
 * @method static void flush()
 * @method static void close()
 * @method static void setUser(string|null $userId)
 *
 * @see TalariaClient
 */
final class Talaria extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TalariaClient::class;
    }
}
