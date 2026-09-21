<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Talaria\TalariaClient;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class LivewireIntegration
{
    private ?Span $active = null;

    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if (!function_exists('\\Livewire\\on')) {
            return;
        }

        \Livewire\on('mount', function (mixed $component): void {
            $this->start($component);
        });
        \Livewire\on('hydrate', function (mixed $component): void {
            $this->start($component);
        });
        \Livewire\on('dehydrate', function (): void {
            $this->finish(ok: true);
        });
        \Livewire\on('destroy', function (): void {
            $this->finish(ok: true);
        });
        \Livewire\on('exception', function (mixed $component, \Throwable $e): void {
            $this->client->captureException($e, [
                'tags' => ['component' => 'livewire'],
            ]);
            $this->finish(ok: false, message: $e->getMessage());
        });
    }

    private function start(mixed $component): void
    {
        if (!$this->client->getConfig()->enableTracing) {
            return;
        }
        if ($this->client->getTracer()->rootSpan() === null) {
            return;
        }
        $name = is_object($component) && method_exists($component, 'getName')
            ? (string) $component->getName()
            : 'component';
        $this->finish(ok: true);
        $this->active = $this->client->startSpan('livewire ' . $name, SpanKind::Internal, [
            'livewire.component' => $name,
        ]);
    }

    private function finish(bool $ok, ?string $message = null): void
    {
        if ($this->active === null) {
            return;
        }
        if ($ok) {
            $this->active->setStatus(SpanStatus::Ok);
        } else {
            $this->active->setStatus(SpanStatus::Error, $message);
        }
        $this->active->end();
        $this->active = null;
    }
}
