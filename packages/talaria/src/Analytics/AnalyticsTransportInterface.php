<?php

declare(strict_types=1);

namespace Talaria\Analytics;

interface AnalyticsTransportInterface
{
    /**
     * Send a batch of analytics events via /v1/analytics.
     *
     * @param list<AnalyticsEvent> $events
     */
    public function sendBatch(array $events): void;
}
