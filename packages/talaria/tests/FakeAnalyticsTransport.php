<?php

declare(strict_types=1);

namespace Talaria\Tests;

use Talaria\Analytics\AnalyticsEvent;
use Talaria\Analytics\AnalyticsTransportInterface;

final class FakeAnalyticsTransport implements AnalyticsTransportInterface
{
    /** @var list<list<AnalyticsEvent>> */
    public array $batches = [];

    public int $attempts = 0;

    public ?\Talaria\Exception\TransportException $failWith = null;

    public function sendBatch(array $events): void
    {
        $this->attempts++;
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->batches[] = array_values($events);
    }

    public function batchCount(): int
    {
        return count($this->batches);
    }

    /**
     * @return list<AnalyticsEvent>
     */
    public function allEvents(): array
    {
        $all = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $event) {
                $all[] = $event;
            }
        }

        return $all;
    }
}
