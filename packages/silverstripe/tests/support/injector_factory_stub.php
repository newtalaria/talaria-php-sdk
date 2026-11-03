<?php

declare(strict_types=1);

namespace SilverStripe\Core\Injector;

/**
 * Stand-in for Silverstripe's Injector Factory interface.
 * Used only when silverstripe/framework is not installed.
 */
interface Factory
{
    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     * @return object
     */
    public function create($service, array $params = []);
}
