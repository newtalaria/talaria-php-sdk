<?php

declare(strict_types=1);

namespace SilverStripe\Core\Injector;

/**
 * Stand-in for Silverstripe's Injector Factory interface.
 * Used only when silverstripe/framework is not installed.
 *
 * Mirrors the strictest supported signature (Silverstripe 6), so framework-less
 * CI still fails if an adapter factory drifts from it. Silverstripe 4/5 declare
 * the same method without types, which the adapter factories also satisfy.
 */
interface Factory
{
    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create(string $service, array $params = []): ?object;
}
