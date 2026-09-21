<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class ConsoleIntegration
{
    private ?Span $active = null;

    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->client->resetRequestState();
            if (!$this->client->getConfig()->enableTracing) {
                return;
            }
            $name = is_string($event->command) && $event->command !== ''
                ? $event->command
                : 'artisan';
            $this->active = $this->client->startTransaction('artisan ' . $name, SpanKind::Internal, [
                'process.command' => $name,
            ]);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($this->active !== null) {
                $exit = (int) $event->exitCode;
                $this->active->setAttribute('process.exit_code', (string) $exit);
                if ($exit !== 0) {
                    $this->active->setStatus(SpanStatus::Error, 'exit ' . $exit);
                } else {
                    $this->active->setStatus(SpanStatus::Ok);
                }
                $this->active->end();
                $this->active = null;
            }
            $this->client->flush();
            $this->client->resetRequestState();
        });
    }
}
