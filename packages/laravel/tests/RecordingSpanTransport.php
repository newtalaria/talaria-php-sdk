<?php

declare(strict_types=1);

namespace Talaria\Laravel\Tests;

use Talaria\Tracing\Span;
use Talaria\Tracing\SpanTransportInterface;

final class RecordingSpanTransport implements SpanTransportInterface
{
    /** @var list<list<Span>> */
    public array $batches = [];

    public function sendBatch(array $spans): void
    {
        $this->batches[] = array_values($spans);
    }

    /**
     * @return list<Span>
     */
    public function allSpans(): array
    {
        $all = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $span) {
                $all[] = $span;
            }
        }

        return $all;
    }
}
