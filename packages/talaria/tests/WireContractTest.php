<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Talaria\Analytics\AnalyticsEvent;
use Talaria\Analytics\AnalyticsEventKind;
use Talaria\Analytics\AnalyticsTransport;
use Talaria\Credential;
use Talaria\Event;
use Talaria\Protocol\StackFrameBuilder;
use Talaria\SeverityLevel;
use Talaria\TalariaClient;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;
use Talaria\Tracing\SpanTransport;
use Talaria\Transport\ServerpodHttpTransport;

/**
 * Locks the JSON each ingest transport posts.
 *
 * These bodies match the live schema on https://ingest-nz1.newtalaria.com:
 * POST /v1/events, /v1/spans, /v1/analytics, and /v1/monitors/{slug}/check-ins.
 */
final class WireContractTest extends TestCase
{
    private const BASE = 'https://ingest-nz1.newtalaria.com';

    public function testEventsBody(): void
    {
        $history = [];
        $http = $this->recordedClient($history);
        $frame = StackFrameBuilder::fromTraceFrame([
            'file' => '/app/src/Checkout.php',
            'line' => 42,
            'function' => 'charge',
            'class' => 'App\\Checkout',
            'type' => '->',
        ]);

        (new ServerpodHttpTransport(self::BASE, Credential::example(), 2.0, $http))->sendBatch([
            new Event(
                message: 'Payment failed',
                level: SeverityLevel::Error,
                title: 'RuntimeException',
                stackTrace: "#0 /app/src/Checkout.php(42): App\\Checkout->charge()\n#1 {main}",
                release: '1.2.3',
                commitSha: 'abc123def456',
                userId: 'user-1',
                sessionId: 'sess-1',
                requestId: 'req-1',
                url: 'https://example.com/checkout',
                tags: ['module' => 'checkout', 'service.name' => 'api'],
                extraJson: json_encode(['cart_id' => 'abc'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                timestamp: '2026-07-19T10:00:00.000Z',
                exception: [
                    'values' => [[
                        'type' => 'RuntimeException',
                        'value' => 'Payment failed',
                        'code' => '0',
                        'mechanism' => ['type' => 'generic', 'handled' => true, 'synthetic' => false],
                        'stacktrace' => ['frames' => [$frame]],
                    ]],
                ],
                platform: 'php',
                traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
                spanId: '00f067aa0ba902b7',
                breadcrumbs: [[
                    'timestamp' => '2026-07-19T09:59:59.000Z',
                    'type' => 'http',
                    'category' => 'request',
                    'message' => 'GET /checkout',
                    'level' => 'info',
                    'data' => ['status_code' => '200'],
                ]],
                userAgent: 'PHP',
                anonymousId: 'anon-1',
                releaseRefKind: 'tag',
            ),
        ]);

        $this->assertExactBody(
            $history,
            '/v1/events',
            <<<'JSON'
{"events":[{"message":"Payment failed","level":"error","eventType":"error","title":"RuntimeException","stackTrace":"#0 \/app\/src\/Checkout.php(42): App\\Checkout->charge()\n#1 {main}","exception":{"values":[{"type":"RuntimeException","value":"Payment failed","code":"0","mechanism":{"type":"generic","handled":true,"synthetic":false},"stacktrace":{"frames":[{"platform":"php","filename":"Checkout.php","absPath":"\/app\/src\/Checkout.php","inApp":true,"functionName":"App\\Checkout->charge","lineno":42}]}}]},"platform":"php","release":"1.2.3","commitSha":"abc123def456","releaseRefKind":"tag","userId":"user-1","anonymousId":"anon-1","sessionId":"sess-1","requestId":"req-1","url":"https:\/\/example.com\/checkout","tags":{"module":"checkout","service.name":"api"},"extraJson":"{\"cart_id\":\"abc\"}","timestamp":"2026-07-19T10:00:00.000Z","traceId":"4bf92f3577b34da6a3ce929d0e0e4736","spanId":"00f067aa0ba902b7","breadcrumbs":[{"timestamp":"2026-07-19T09:59:59.000Z","type":"http","category":"request","message":"GET \/checkout","level":"info","data":{"status_code":"200"}}],"userAgent":"PHP"}]}
JSON,
        );
    }

    public function testSpansBody(): void
    {
        $history = [];
        $http = $this->recordedClient($history);
        $span = new Span(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            parentSpanId: 'aaaaaaaaaaaaaaaa',
            name: 'GET /products',
            kind: SpanKind::Server->value,
            startTime: '2026-07-19T10:00:00.000Z',
            attributes: [
                'http.request.method' => 'GET',
                'http.route' => '/products',
            ],
            resource: ['service.name' => 'api'],
            release: '1.2.3',
            userId: 'user-1',
            sessionId: 'sess-1',
            requestId: 'req-1',
            anonymousId: 'anon-1',
        );
        $span->setStatus(SpanStatus::Ok, 'HTTP 200');
        $span->end('2026-07-19T10:00:00.012Z');

        (new SpanTransport(self::BASE, Credential::example(), 2.0, $http))->sendBatch([$span]);

        $this->assertExactBody(
            $history,
            '/v1/spans',
            <<<'JSON'
{"spans":[{"traceId":"4bf92f3577b34da6a3ce929d0e0e4736","spanId":"00f067aa0ba902b7","name":"GET \/products","kind":"server","startTime":"2026-07-19T10:00:00.000Z","endTime":"2026-07-19T10:00:00.012Z","status":"ok","parentSpanId":"aaaaaaaaaaaaaaaa","statusMessage":"HTTP 200","attributes":{"http.request.method":"GET","http.route":"\/products"},"resource":{"service.name":"api"},"release":"1.2.3","userId":"user-1","anonymousId":"anon-1","sessionId":"sess-1","requestId":"req-1"}]}
JSON,
        );
    }

    public function testAnalyticsBody(): void
    {
        $history = [];
        $http = $this->recordedClient($history);
        (new AnalyticsTransport(self::BASE, Credential::example(), 2.0, $http))->sendBatch([
            new AnalyticsEvent(
                name: 'product_viewed',
                kind: AnalyticsEventKind::Track,
                anonymousId: 'anon-1',
                sessionId: 'sess-1',
                timestamp: '2026-07-19T10:00:00.000Z',
                eventId: 'evt-1',
                userId: 'user-1',
                platform: 'php',
                release: '1.2.3',
                url: 'https://example.com/products/1',
                path: '/products/1',
                title: 'Trail Runner',
                propertiesJson: json_encode(['price' => 129.99], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
        ]);

        $this->assertExactBody(
            $history,
            '/v1/analytics',
            <<<'JSON'
{"events":[{"name":"product_viewed","kind":"track","anonymousId":"anon-1","sessionId":"sess-1","timestamp":"2026-07-19T10:00:00.000Z","eventId":"evt-1","userId":"user-1","platform":"php","release":"1.2.3","url":"https:\/\/example.com\/products\/1","path":"\/products\/1","title":"Trail Runner","propertiesJson":"{\"price\":129.99}"}]}
JSON,
        );
    }

    public function testMonitorCheckInBody(): void
    {
        $history = [];
        $http = $this->recordedClient($history);
        $client = new TalariaClient([
            'url' => self::BASE,
            'apiKey' => Credential::example(),
            'defaultIntegrations' => false,
            'remoteConfig' => false,
        ], new ServerpodHttpTransport(self::BASE, Credential::example(), 2.0, $http));

        $client->checkIn('process-job-queue', 'ok', [
            'crontab' => '* * * * *',
            'timezone' => 'Pacific/Auckland',
            'maxRuntimeSeconds' => 55,
            'durationSeconds' => 1.5,
            'logTail' => 'done',
            'resource' => ['service.name' => 'cron'],
        ]);

        $this->assertExactBody(
            $history,
            '/v1/monitors/process-job-queue/check-ins',
            <<<'JSON'
{"slug":"process-job-queue","status":"ok","crontab":"* * * * *","timezone":"Pacific\/Auckland","maxRuntimeSeconds":55,"durationSeconds":1.5,"logTail":"done","resource":{"service.name":"cron"}}
JSON,
        );
    }

    /**
     * @param list<array{request: RequestInterface}> $history
     */
    private function recordedClient(array &$history): GuzzleClient
    {
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new GuzzleClient(['handler' => $stack]);
    }

    /**
     * @param list<array{request: RequestInterface}> $history
     */
    private function assertExactBody(array $history, string $path, string $expected): void
    {
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . $path, (string) $request->getUri());
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer ' . Credential::example(), $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('X-API-Key'));
        self::assertStringNotContainsString('"input"', (string) $request->getBody());
        self::assertStringNotContainsString('__className__', (string) $request->getBody());
        self::assertStringNotContainsString('"environment"', (string) $request->getBody());
        self::assertSame(rtrim($expected), (string) $request->getBody());
    }
}
