<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class QueueIntegration
{
    private ?Span $active = null;

    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if (class_exists(JobQueued::class)) {
            Event::listen(JobQueued::class, function (JobQueued $event): void {
                if (!$this->client->getConfig()->enableTracing) {
                    return;
                }
                $name = self::jobName($event->job);
                $span = $this->client->startSpan('queue.publish ' . $name, SpanKind::Producer, [
                    'messaging.system' => 'laravel',
                    'messaging.operation' => 'publish',
                    'messaging.destination.name' => (string) ($event->queue ?? 'default'),
                ]);
                $span->setStatus(SpanStatus::Ok);
                $span->end();
            });
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->client->resetRequestState();
            if (!$this->client->getConfig()->enableTracing) {
                return;
            }
            $job = $event->job;
            $name = method_exists($job, 'resolveName') ? (string) $job->resolveName() : $job::class;
            $this->active = $this->client->startTransaction('queue.process ' . $name, SpanKind::Consumer, [
                'messaging.system' => 'laravel',
                'messaging.operation' => 'process',
                'messaging.destination.name' => method_exists($job, 'getQueue') ? (string) $job->getQueue() : 'default',
            ]);
        });

        Event::listen(JobProcessed::class, function (): void {
            if ($this->active !== null) {
                $this->active->setStatus(SpanStatus::Ok);
                $this->active->end();
                $this->active = null;
            }
            $this->client->flush();
            $this->client->resetRequestState();
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->client->captureException($event->exception, [
                'tags' => ['component' => 'queue'],
            ]);
            if ($this->active !== null) {
                $this->active->setStatus(SpanStatus::Error, $event->exception->getMessage());
                $this->active->end();
                $this->active = null;
            }
            $this->client->flush();
            $this->client->resetRequestState();
        });
    }

    private static function jobName(mixed $job): string
    {
        if (is_object($job)) {
            return $job::class;
        }
        if (is_string($job) && $job !== '') {
            return $job;
        }

        return 'job';
    }
}
