<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;

final class HorizonIntegration
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if (!class_exists(\Laravel\Horizon\Horizon::class)) {
            return;
        }

        Event::listen(JobProcessing::class, function (): void {
            $span = $this->client->getTracer()->currentSpan()
                ?? $this->client->getTracer()->rootSpan();
            $span?->setAttribute('messaging.system', 'horizon');
        });
    }
}
