<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Talaria\TalariaClient;

/**
 * Guzzle middleware for **application** HTTP clients.
 *
 * Do not install this on {@see \Talaria\Transport\ServerpodHttpTransport} or
 * {@see SpanTransport} — ingest must not span itself.
 *
 * Calls to api.openai.com and api.anthropic.com on the known chat, completion,
 * and embedding paths are one GenAI client span. Prompt and completion text
 * are not stored.
 */
final class GuzzleMiddleware
{
    public static function create(TalariaClient $client): callable
    {
        return static function (callable $handler) use ($client): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $client) {
                $path = $request->getUri()->getPath();
                if (str_contains($path, '/events/ingestBatch') || str_contains($path, '/spans/ingestBatch')) {
                    return $handler($request, $options);
                }

                $tracer = $client->getTracer();
                if (!$tracer->isEnabled()) {
                    return $handler($request, $options);
                }

                $classified = ModelHttp::classify($request->getMethod(), $request->getUri()->getHost(), $path);
                if ($classified !== null) {
                    if ($tracer->rootSpan() === null) {
                        return $handler($request, $options);
                    }

                    return self::modelCall($handler, $client, $tracer, $request, $options, $classified);
                }

                $method = $request->getMethod();
                $uri = $request->getUri();
                $host = $uri->getHost();
                $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
                $name = $host !== '' ? $method . ' ' . $host . $path : $method . ' ' . $path;
                $span = $tracer->startSpan(
                    $name,
                    SpanKind::Client,
                    [
                        'http.request.method' => $method,
                        'url.full' => UrlSanitizer::sanitize((string) $uri),
                        'server.address' => $host,
                    ],
                );

                if ($span->isRecording()) {
                    $request = $request->withHeader(
                        'traceparent',
                        TraceContext::format($span->traceId, $span->spanId, $tracer->isSampled()),
                    );
                }

                $finish = static function (?ResponseInterface $response, mixed $reason) use ($span, $client, $method): void {
                    if ($response !== null) {
                        $status = $response->getStatusCode();
                        $span->setAttribute('http.response.status_code', (string) $status);
                        if ($status >= 500) {
                            $span->setStatus(SpanStatus::Error, 'HTTP ' . $status);
                        } else {
                            $span->setStatus(SpanStatus::Ok);
                        }
                    }
                    if ($reason !== null) {
                        $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                        $span->setStatus(SpanStatus::Error, $message !== '' ? $message : 'request failed');
                    }
                    $span->end();
                    $client->addBreadcrumb([
                        'type' => 'http',
                        'category' => 'http',
                        'message' => $span->name,
                        'level' => $reason !== null ? 'error' : 'info',
                        'data' => [
                            'method' => $method,
                        ],
                    ]);
                };

                return self::invoke($handler, $request, $options, $finish, false);
            };
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param array{provider: string, operation: string, host: string} $classified
     */
    private static function modelCall(
        callable $handler,
        TalariaClient $client,
        Tracer $tracer,
        RequestInterface $request,
        array $options,
        array $classified,
    ): mixed {
        $fields = ModelHttp::requestFields(self::requestJson($request, $options));
        $model = $fields['model'];
        $name = ModelHttp::spanName($classified['operation'], $model);
        $attributes = [
            'gen_ai.operation.name' => $classified['operation'],
            'gen_ai.provider.name' => $classified['provider'],
            'server.address' => $classified['host'],
            'http.request.method' => 'POST',
        ];
        if ($model !== null) {
            $attributes['gen_ai.request.model'] = $model;
        }
        $span = $tracer->startSpan($name, SpanKind::Client, $attributes);
        if ($span->isRecording()) {
            $request = $request->withHeader(
                'traceparent',
                TraceContext::format($span->traceId, $span->spanId, $tracer->isSampled()),
            );
        }

        $finish = static function (?ResponseInterface $response, mixed $reason) use (
            $span,
            $client,
            $tracer,
            $classified,
            $model,
            $fields,
            $name,
        ): void {
            $status = $response?->getStatusCode();
            $failed = $reason !== null || ($status !== null && $status >= 400);
            if ($response !== null && $status !== null && $status < 400 && $fields['stream'] !== true) {
                $contentType = strtolower($response->getHeaderLine('Content-Type'));
                if (!str_contains($contentType, 'text/event-stream')) {
                    $usage = ModelHttp::usageFields(ModelHttp::readJson($response->getBody()));
                    if ($usage['responseModel'] !== null) {
                        $span->setAttribute('gen_ai.response.model', $usage['responseModel']);
                    }
                    if ($usage['inputTokens'] !== null) {
                        $span->setAttribute('gen_ai.usage.input_tokens', $usage['inputTokens']);
                    }
                    if ($usage['outputTokens'] !== null) {
                        $span->setAttribute('gen_ai.usage.output_tokens', $usage['outputTokens']);
                    }
                }
            }
            if ($status !== null) {
                $span->setAttribute('http.response.status_code', (string) $status);
            }
            if ($reason instanceof \Throwable) {
                if ($status === null && method_exists($reason, 'getResponse')) {
                    $errorResponse = $reason->getResponse();
                    if ($errorResponse instanceof ResponseInterface) {
                        $status = $errorResponse->getStatusCode();
                        $span->setAttribute('http.response.status_code', (string) $status);
                    }
                }
                $errorType = ModelHttp::exceptionType($reason);
                $span->setStatus(SpanStatus::Error, $errorType);
                $span->setAttribute('error.type', $errorType);
                /** @var array{model?: string, operation: string, provider: string, statusCode?: int} $stamp */
                $stamp = [
                    'operation' => $classified['operation'],
                    'provider' => $classified['provider'],
                ];
                if (is_string($model) && $model !== '') {
                    $stamp['model'] = $model;
                }
                if (is_int($status)) {
                    $stamp['statusCode'] = $status;
                }
                ModelCallContext::stamp($reason, $stamp);
            } elseif ($status !== null && $status >= 400) {
                $label = 'HTTP ' . $status;
                $span->setStatus(SpanStatus::Error, $label);
                $span->setAttribute('error.type', $label);
                if ($status >= 500) {
                    $root = $tracer->rootSpan();
                    $rootStatus = $root !== null && $root !== $span ? $root->getStatus() : null;
                    $tracer->markError($label);
                    if (
                        $root !== null
                        && $root !== $span
                        && $rootStatus !== null
                        && $rootStatus !== SpanStatus::Error->value
                    ) {
                        $root->setStatus($rootStatus);
                    }
                }
            } else {
                $span->setStatus(SpanStatus::Ok);
            }
            $span->end();
            $data = [
                'gen_ai.operation.name' => $classified['operation'],
                'gen_ai.provider.name' => $classified['provider'],
            ];
            if ($model !== null) {
                $data['gen_ai.request.model'] = $model;
            }
            if ($status !== null) {
                $data['http.response.status_code'] = (string) $status;
            }
            $client->addBreadcrumb([
                'type' => 'http',
                'category' => 'gen_ai',
                'message' => $name,
                'level' => $failed ? 'error' : 'info',
                'data' => $data,
            ]);
        };

        return self::invoke($handler, $request, $options, $finish, true);
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): mixed $handler
     * @param callable(?ResponseInterface, mixed): void $finish
     */
    /**
     * The body is still empty when this middleware runs outside Guzzle's
     * prepare step, which is the documented handler-stack order. The `json`
     * request option is that same payload.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private static function requestJson(RequestInterface $request, array $options): ?array
    {
        $decoded = ModelHttp::readJson($request->getBody());
        if ($decoded !== null) {
            return $decoded;
        }
        $json = $options['json'] ?? null;
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return null;
        }
        $parsed = json_decode($json, true);

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function invoke(
        callable $handler,
        RequestInterface $request,
        array $options,
        callable $finish,
        bool $attachErrorResponse,
    ): mixed {
        $errorResponse = static function (mixed $reason) use ($attachErrorResponse): ?ResponseInterface {
            if (!$attachErrorResponse || !$reason instanceof \Throwable || !method_exists($reason, 'getResponse')) {
                return null;
            }
            $response = $reason->getResponse();

            return $response instanceof ResponseInterface ? $response : null;
        };

        try {
            $result = $handler($request, $options);
            if (is_object($result) && method_exists($result, 'then')) {
                return $result->then(
                    static function ($response) use ($finish) {
                        $finish($response instanceof ResponseInterface ? $response : null, null);

                        return $response;
                    },
                    static function ($reason) use ($finish, $errorResponse) {
                        $finish($errorResponse($reason), $reason);
                        throw $reason instanceof \Throwable
                            ? $reason
                            : new \RuntimeException((string) $reason);
                    },
                );
            }

            $finish($result instanceof ResponseInterface ? $result : null, null);

            return $result;
        } catch (\Throwable $e) {
            $finish($errorResponse($e), $e);
            throw $e;
        }
    }
}
