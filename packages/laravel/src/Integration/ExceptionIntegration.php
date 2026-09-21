<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

/**
 * Exception reporting is registered on the Laravel handler from the service
 * provider so the app's reporter still runs. This class exists so the
 * integration map stays explicit.
 */
final class ExceptionIntegration
{
    public function register(): void
    {
    }
}
