<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;

final class OctaneIntegration
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        $events = [
            'Laravel\\Octane\\Events\\RequestReceived',
            'Laravel\\Octane\\Events\\TaskReceived',
            'Laravel\\Octane\\Events\\TickReceived',
        ];
        foreach ($events as $event) {
            if (!class_exists($event)) {
                continue;
            }
            Event::listen($event, function (): void {
                $this->client->resetRequestState();
            });
        }

        $flushEvents = [
            'Laravel\\Octane\\Events\\RequestTerminated',
            'Laravel\\Octane\\Events\\TaskTerminated',
            'Laravel\\Octane\\Events\\TickTerminated',
        ];
        foreach ($flushEvents as $event) {
            if (!class_exists($event)) {
                continue;
            }
            Event::listen($event, function (): void {
                $this->client->flush();
                $this->client->resetRequestState();
            });
        }
    }
}
