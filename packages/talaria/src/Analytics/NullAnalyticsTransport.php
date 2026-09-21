<?php

declare(strict_types=1);

namespace Talaria\Analytics;

/**
 * No-op analytics transport used when the SDK is disabled or misconfigured.
 */
final class NullAnalyticsTransport implements AnalyticsTransportInterface
{
    public function sendBatch(array $events): void
    {
        // intentionally empty
    }
}
