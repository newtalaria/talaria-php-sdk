<?php

declare(strict_types=1);

namespace Talaria\Analytics;

use Talaria\Exception\TransportException;

/**
 * In-memory queue that drains via /v1/analytics on size or age thresholds.
 *
 * @phpstan-type ClockCallable callable(): float
 * @phpstan-type ErrorHandlerCallable callable(TransportException): void
 */
final class AnalyticsQueue
{
    /** @var list<array{event: AnalyticsEvent, enqueuedAt: float}> */
    private array $buffer = [];

    private bool $draining = false;

    /** @var ClockCallable */
    private $clock;

    /** @var ErrorHandlerCallable|null */
    private $onError;

    /** @var (callable(): bool)|null */
    private $isPaused;

    /**
     * @param ClockCallable|null $clock Returns unix time in seconds (fractional ok)
     * @param ErrorHandlerCallable|null $onError
     */
    public function __construct(
        private readonly AnalyticsTransportInterface $transport,
        private readonly int $maxBatchSize = 50,
        private readonly int $flushIntervalMs = 2000,
        $clock = null,
        $onError = null,
        $isPaused = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->onError = $onError;
        $this->isPaused = $isPaused;
    }

    public function enqueue(AnalyticsEvent $event): void
    {
        $this->buffer[] = [
            'event' => $event,
            'enqueuedAt' => ($this->clock)(),
        ];

        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->draining || $this->buffer === [] || $this->isPaused()) {
            return;
        }

        $this->draining = true;
        try {
            while ($this->buffer !== []) {
                $slice = array_splice($this->buffer, 0, $this->maxBatchSize);
                /** @var list<AnalyticsEvent> $events */
                $events = array_map(static fn (array $item) => $item['event'], $slice);

                try {
                    $this->transport->sendBatch($events);
                } catch (TransportException $e) {
                    if ($this->onError !== null) {
                        ($this->onError)($e);
                    }
                } catch (\Throwable $e) {
                    // Encoding, client or other faults never reach the host app.
                    if ($this->onError !== null) {
                        ($this->onError)(new TransportException('Talaria batch send failed: ' . $e->getMessage(), null, $e));
                    }
                }
            }
        } finally {
            $this->draining = false;
        }
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    private function isPaused(): bool
    {
        return $this->isPaused !== null && ($this->isPaused)();
    }

    private function shouldFlush(): bool
    {
        if (count($this->buffer) >= $this->maxBatchSize) {
            return true;
        }

        if ($this->flushIntervalMs <= 0 || $this->buffer === []) {
            return false;
        }

        $oldest = $this->buffer[0]['enqueuedAt'];
        $ageMs = (($this->clock)() - $oldest) * 1000.0;

        return $ageMs >= $this->flushIntervalMs;
    }
}
