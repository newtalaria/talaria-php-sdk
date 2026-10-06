<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\TalariaClient;
use Talaria\Tracing\GuzzleMiddleware;
use Talaria\Tracing\SpanKind;

final class GuzzleMiddlewareTest extends TestCase
{
    public function testInjectsTraceparentOnAppClient(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $stack = HandlerStack::create($mock);
        // Last push is innermost; history must sit inside Talaria so it sees injected headers.
        $stack->push(GuzzleMiddleware::create($client), 'talaria_tracing');
        $stack->push(Middleware::history($history));

        $root = $client->startTransaction('GET /page', SpanKind::Server);
        $http = new GuzzleClient(['handler' => $stack]);
        $http->request('GET', 'https://payments.example.com/charge?card=secret');
        $root->end();
        $client->flush();

        self::assertCount(1, $history);
        $outgoing = $history[0]['request'];
        $traceparent = $outgoing->getHeaderLine('traceparent');
        self::assertNotSame('', $traceparent);
        self::assertStringStartsWith('00-' . $root->traceId . '-', $traceparent);

        $child = null;
        foreach ($spans->allSpans() as $span) {
            if ($span->kind === SpanKind::Client->value) {
                $child = $span;
            }
        }
        self::assertNotNull($child);
        self::assertSame('GET payments.example.com/charge', $child->name);
        $wire = $child->toWire();
        self::assertSame('GET', $wire['attributes']['http.request.method']);
        self::assertSame('200', $wire['attributes']['http.response.status_code']);
        self::assertStringNotContainsString('secret', $wire['attributes']['url.full']);
    }

    public function testSkipsTalariaIngestUrls(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($client), 'talaria_tracing');
        $stack->push(Middleware::history($history));

        $root = $client->startTransaction('GET /page', SpanKind::Server);
        $http = new GuzzleClient(['handler' => $stack]);
        $http->send(new Request('POST', 'https://api.example.com/spans/ingestBatch'));
        $root->end();
        $client->flush();

        self::assertCount(1, $history);
        self::assertSame('', $history[0]['request']->getHeaderLine('traceparent'));
        $kinds = array_map(static fn ($span) => $span->kind, $spans->allSpans());
        self::assertNotContains(SpanKind::Client->value, $kinds);
    }

    public function testOpenAiChatSpanKeepsUsageAndDropsPromptText(): void
    {
        $body = json_encode([
            'model' => 'gpt-4o-2024-08-06',
            'choices' => [['message' => ['content' => 'SECRET_COMPLETION']]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ], JSON_THROW_ON_ERROR);
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $response = $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'json' => [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => 'SECRET_PROMPT']],
            ],
        ]);
        $root->end();
        $client->flush();

        self::assertStringContainsString('SECRET_COMPLETION', (string) $response->getBody());
        $child = $this->modelSpan($spans);
        self::assertNotNull($child);
        $wire = $child->toWire();
        self::assertSame('chat gpt-4o', $wire['name']);
        self::assertSame('client', $wire['kind']);
        self::assertSame('ok', $wire['status']);
        self::assertArrayHasKey('parentSpanId', $wire);
        $attributes = $wire['attributes'];
        self::assertSame('chat', $attributes['gen_ai.operation.name']);
        self::assertSame('openai', $attributes['gen_ai.provider.name']);
        self::assertSame('gpt-4o', $attributes['gen_ai.request.model']);
        self::assertSame('gpt-4o-2024-08-06', $attributes['gen_ai.response.model']);
        self::assertSame('11', $attributes['gen_ai.usage.input_tokens']);
        self::assertSame('7', $attributes['gen_ai.usage.output_tokens']);
        self::assertSame('api.openai.com', $attributes['server.address']);
        self::assertSame('200', $attributes['http.response.status_code']);
        self::assertStringNotContainsString('SECRET_PROMPT', json_encode($attributes, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SECRET_COMPLETION', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function testAnthropicMessagesMapTokenFields(): void
    {
        $body = json_encode([
            'model' => 'claude-3-5-sonnet-20241022',
            'content' => [['text' => 'SECRET_COMPLETION']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
        ], JSON_THROW_ON_ERROR);
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $http->request('POST', 'https://api.anthropic.com/v1/messages', [
            'json' => ['model' => 'claude-3-5-sonnet', 'messages' => [['content' => 'SECRET_PROMPT']]],
        ]);
        $root->end();
        $client->flush();

        $wire = $this->modelSpan($spans)->toWire();
        self::assertSame('chat claude-3-5-sonnet', $wire['name']);
        self::assertSame('anthropic', $wire['attributes']['gen_ai.provider.name']);
        self::assertSame('3', $wire['attributes']['gen_ai.usage.input_tokens']);
        self::assertSame('4', $wire['attributes']['gen_ai.usage.output_tokens']);
        self::assertStringNotContainsString('SECRET', json_encode($wire['attributes'], JSON_THROW_ON_ERROR));
    }

    public function testStreamOmitsTokensAndStoresNoSseText(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/event-stream'], "data: SECRET_COMPLETION\n\n"),
        ]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $response = $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'json' => ['model' => 'gpt-4o', 'stream' => true, 'messages' => [['content' => 'SECRET_PROMPT']]],
        ]);
        $root->end();
        $client->flush();

        self::assertStringContainsString('SECRET_COMPLETION', (string) $response->getBody());
        $attributes = $this->modelSpan($spans)->toWire()['attributes'];
        self::assertArrayNotHasKey('gen_ai.usage.input_tokens', $attributes);
        self::assertArrayNotHasKey('gen_ai.usage.output_tokens', $attributes);
        self::assertStringNotContainsString('SECRET', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function testClientErrorSetsSpanStatusWithoutForcingSample(): void
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"SECRET_PROMPT"}'),
        ]);
        $spans = new FakeSpanTransport();
        $events = new FakeTransport();
        $client = $this->client($spans, $events, 0.0);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $response = $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'http_errors' => false,
            'json' => ['model' => 'gpt-4o'],
        ]);
        self::assertSame(400, $response->getStatusCode());
        $root->end();
        $client->flush();

        self::assertSame([], $spans->allSpans());
    }

    public function testClientErrorSpanStatusWhenSampled(): void
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"SECRET_PROMPT"}'),
        ]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'http_errors' => false,
            'json' => ['model' => 'gpt-4o'],
        ]);
        $root->end();
        $client->flush();

        $wire = $this->modelSpan($spans)->toWire();
        self::assertSame('error', $wire['status']);
        self::assertSame('HTTP 400', $wire['statusMessage']);
        self::assertSame('HTTP 400', $wire['attributes']['error.type']);
        self::assertStringNotContainsString('SECRET', json_encode($wire, JSON_THROW_ON_ERROR));
    }

    public function testServerErrorForcesSampleWithoutRewritingTheRoot(): void
    {
        $mock = new MockHandler([new Response(500, [], '{"error":"overloaded"}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans, sampleRate: 0.0);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'http_errors' => false,
            'json' => ['model' => 'gpt-4o'],
        ]);
        $root->end();
        $client->flush();

        $child = $this->modelSpan($spans);
        self::assertNotNull($child);
        self::assertSame('error', $child->toWire()['status']);
        self::assertSame('HTTP 500', $child->toWire()['statusMessage']);
        $rootWire = null;
        foreach ($spans->allSpans() as $span) {
            if ($span->kind === SpanKind::Server->value) {
                $rootWire = $span->toWire();
            }
        }
        self::assertNotNull($rootWire);
        self::assertNotSame('error', $rootWire['status']);
    }

    public function testThrownCallStampsTheErrorForCapture(): void
    {
        $request = new Request('POST', 'https://api.openai.com/v1/chat/completions');
        $mock = new MockHandler([
            new RequestException('SECRET_PROMPT leaked', $request, new Response(500)),
        ]);
        $spans = new FakeSpanTransport();
        $events = new FakeTransport();
        $client = $this->client($spans, $events);
        $http = $this->http($client, $mock);

        $root = $client->startTransaction('POST /checkout', SpanKind::Server);
        $caught = null;
        try {
            $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'json' => ['model' => 'gpt-4o', 'messages' => [['content' => 'SECRET_PROMPT']]],
            ]);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertInstanceOf(\Throwable::class, $caught);
        $client->captureException($caught);
        $root->end();
        $client->flush();

        $wire = $this->modelSpan($spans)->toWire();
        self::assertSame('error', $wire['status']);
        self::assertSame('RequestException', $wire['statusMessage']);
        self::assertSame('RequestException', $wire['attributes']['error.type']);
        self::assertStringNotContainsString('SECRET_PROMPT', (string) $wire['statusMessage']);

        $event = $events->allEvents()[0];
        self::assertSame('gpt-4o', $event->tags['gen_ai.request.model']);
        self::assertSame('chat', $event->tags['gen_ai.operation.name']);
        self::assertSame('openai', $event->tags['gen_ai.provider.name']);
        $extra = json_decode((string) $event->extraJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(500, $extra['status_code']);
        $categories = array_map(static fn (array $crumb): string => (string) ($crumb['category'] ?? ''), $event->breadcrumbs ?? []);
        self::assertContains('gen_ai', $categories);
    }

    public function testModelCallWithoutATransactionRecordsNothing(): void
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $http = $this->http($client, $mock);

        $response = $http->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'json' => ['model' => 'gpt-4o'],
        ]);
        $client->flush();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $spans->allSpans());
    }

    private function http(TalariaClient $client, MockHandler $mock): GuzzleClient
    {
        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($client), 'talaria_tracing');

        return new GuzzleClient(['handler' => $stack]);
    }

    private function modelSpan(FakeSpanTransport $spans): ?\Talaria\Tracing\Span
    {
        foreach ($spans->allSpans() as $span) {
            $attributes = $span->toWire()['attributes'] ?? [];
            if (is_array($attributes) && isset($attributes['gen_ai.operation.name'])) {
                return $span;
            }
        }

        return null;
    }

    private function client(FakeSpanTransport $spans, ?FakeTransport $events = null, float $sampleRate = 1.0): TalariaClient
    {
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $events ?? new FakeTransport(), spanTransport: $spans);
        $client->getConfig()->applySdkDocument([
            'schemaVersion' => 1,
            'active' => true,
            'tracing' => ['enabled' => true, 'tracesSampleRate' => $sampleRate],
        ]);

        return $client;
    }
}
