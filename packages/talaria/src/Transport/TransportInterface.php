<?php

declare(strict_types=1);

namespace Talaria\Transport;

use Talaria\Event;

interface TransportInterface
{
    /**
     * Send a batch of events via /v1/events.
     *
     * @param list<Event> $events
     */
    public function sendBatch(array $events): void;
}
