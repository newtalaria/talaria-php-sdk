<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;
use Talaria\Tracing\TraceContext;
use Talaria\Tracing\UrlSanitizer;

final class HttpClientIntegration
{
    private ?Span $active = null;

    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        if (class_exists(\Illuminate\Support\Facades\Http::class)
            && method_exists(\Illuminate\Support\Facades\Http::class, 'globalRequestMiddleware')) {
            \Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) {
                $url = method_exists($request, 'url') ? (string) $request->url() : '';
                if (str_contains($url, '/events/ingestBatch') || str_contains($url, '/spans/ingestBatch')) {
                    return $request;
                }
                $traceparent = $this->client->getTraceparent();
                if ($traceparent !== null && method_exists($request, 'withHeader')) {
                    return $request->withHeader('traceparent', $traceparent);
                }

                return $request;
            });
        }

        Event::listen(RequestSending::class, function (RequestSending $event): void {
            $request = $event->request;
            $url = (string) $request->url();
            if (str_contains($url, '/events/ingestBatch') || str_contains($url, '/spans/ingestBatch')) {
                return;
            }

            $traceparent = $this->client->getTraceparent();
            if ($traceparent !== null && method_exists($request, 'withHeaders')) {
                $request->withHeaders(['traceparent' => $traceparent]);
            }

            if (!$this->client->getConfig()->enableTracing) {
                return;
            }

            $method = strtoupper((string) $request->method());
            $this->active = $this->client->startSpan(
                $method . ' ' . UrlSanitizer::sanitize($url),
                SpanKind::Client,
                [
                    'http.request.method' => $method,
                    'url.full' => UrlSanitizer::sanitize($url),
                ],
            );
            if ($this->active->isRecording()) {
                $request->withHeaders([
                    'traceparent' => TraceContext::format(
                        $this->active->traceId,
                        $this->active->spanId,
                        $this->client->getTracer()->isSampled(),
                    ),
                ]);
            }
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($this->active === null) {
                return;
            }
            $status = $event->response->status();
            $this->active->setAttribute('http.response.status_code', (string) $status);
            if ($status >= 500) {
                $this->active->setStatus(SpanStatus::Error, 'HTTP ' . $status);
            } else {
                $this->active->setStatus(SpanStatus::Ok);
            }
            $this->active->end();
            $this->active = null;
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            if ($this->active === null) {
                return;
            }
            $exception = $event->exception ?? null;
            $message = $exception instanceof \Throwable ? $exception->getMessage() : 'connection failed';
            $this->active->setStatus(SpanStatus::Error, $message);
            $this->active->end();
            $this->active = null;
        });
    }
}
