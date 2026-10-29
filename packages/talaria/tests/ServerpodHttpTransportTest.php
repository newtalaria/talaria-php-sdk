<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\Event;
use Talaria\Exception\TransportException;
use Talaria\SeverityLevel;
use Talaria\Transport\ServerpodHttpTransport;

final class ServerpodHttpTransportTest extends TestCase
{
    public function testSendBatchPostsToV1EventsEndpoint(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);

        $transport = new ServerpodHttpTransport(
            'https://api.example.com/',
            \Talaria\Credential::example(),
            2.0,
            $http,
        );

        $transport->sendBatch([
            new Event(
                message: 'First',
                level: SeverityLevel::Warning,
            ),
            new Event(
                message: 'Second',
                level: SeverityLevel::Error,
            ),
        ]);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.com/v1/events', (string) $request->getUri());
        self::assertSame('Bearer ' . \Talaria\Credential::example(), $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('X-API-Key'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input', $body);
        self::assertCount(2, $body['events']);
        self::assertArrayNotHasKey('__className__', $body['events'][0]);
        self::assertSame('First', $body['events'][0]['message']);
        self::assertArrayNotHasKey('environment', $body['events'][0]);
        self::assertSame('Second', $body['events'][1]['message']);
    }

    public function testClientReportPostsPlainJsonWithBearer(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(202, [], '')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);
        $transport = new ServerpodHttpTransport('https://ingest-nz1.newtalaria.com', \Talaria\Credential::example(), 2.0, $http);

        $transport->postClientReport([
            'discards' => [
                ['signal' => 'error', 'reason' => 'sample_rate', 'count' => 2],
            ],
        ]);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://ingest-nz1.newtalaria.com/v1/client-reports', (string) $request->getUri());
        self::assertSame('Bearer ' . \Talaria\Credential::example(), $request->getHeaderLine('Authorization'));
        self::assertSame(
            '{"discards":[{"signal":"error","reason":"sample_rate","count":2}]}',
            (string) $request->getBody(),
        );
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('input', $body);
        self::assertArrayNotHasKey('discardedEvents', $body);
        self::assertSame('error', $body['discards'][0]['signal']);
        self::assertSame('sample_rate', $body['discards'][0]['reason']);
        self::assertSame(2, $body['discards'][0]['count']);
    }

    public function testNonSuccessThrows(): void
    {
        $mock = new MockHandler([new Response(429, [], '{"message":"quota exceeded"}')]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $transport = new ServerpodHttpTransport('https://api.example.com', \Talaria\Credential::example(), 2.0, $http);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('429');
        $transport->sendBatch([
            new Event(message: 'x', level: SeverityLevel::Info),
        ]);
    }

    public function testParsesRetryAndClassNameFromBody(): void
    {
        $mock = new MockHandler([
            new Response(401, ['Content-Type' => 'application/problem+json'], json_encode([
                'type' => 'https://newtalaria.com/docs/reference/errors#invalid_key',
                'title' => 'Invalid key',
                'status' => 401,
                'detail' => 'Invalid API key',
                'code' => 'invalid_key',
                'requestId' => 'req_test',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $transport = new ServerpodHttpTransport('https://api.example.com', \Talaria\Credential::example(), 2.0, $http);

        try {
            $transport->sendBatch([
                new Event(message: 'x', level: SeverityLevel::Info),
            ]);
            self::fail('expected TransportException');
        } catch (TransportException $e) {
            self::assertSame('invalid_key', $e->problemCode);
            self::assertSame('req_test', $e->requestId);
            self::assertFalse($e->retry);
            self::assertTrue($e->isPermanent());
            self::assertSame('Invalid API key', $e->bodyMessage);
        }
    }
}
