<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

/**
 * HTTP SERVER spans are provided by {@see \Talaria\Laravel\Http\Middleware\TracingMiddleware}.
 */
final class RequestIntegration
{
    public function register(): void
    {
    }
}
