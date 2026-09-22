<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\Analytics\AnalyticsEvent;
use Talaria\Analytics\AnalyticsEventKind;
use Talaria\Analytics\AnalyticsTransport;
use Talaria\Context\RuntimeContext;
use Talaria\Exception\TransportException;
use Talaria\TalariaClient;

final class AnalyticsTest extends TestCase
{
    public function testEnableAnalyticsFalseDropsTrack(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, [
            'anonymousId' => 'anon-1',
            'enableAnalytics' => false,
        ]);

        self::assertFalse($client->analytics->isEnabled());
        $client->analytics->track('product_viewed');
        $client->flush();

        self::assertSame(0, $analytics->batchCount());
        self::assertSame(0, $client->analyticsQueueSize());
    }

    public function testTrackRequiresIdentity(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics);

        $client->analytics->track('product_viewed', ['product_id' => '123']);
        $client->flush();

        self::assertSame(0, $analytics->batchCount());
        self::assertSame(0, $client->analyticsQueueSize());
    }

    public function testTrackWithAnonymousIdSendsBatchEnvelope(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, ['anonymousId' => 'anon-browser']);

        $client->analytics->track('product_viewed', [
            'product_id' => '123',
            'price' => 129.99,
        ]);
        self::assertSame(1, $client->analyticsQueueSize());
        $client->flush();

        self::assertSame(1, $analytics->batchCount());
        self::assertCount(1, $analytics->batches[0]);
        $event = $analytics->batches[0][0];
        $wire = $event->toWire();

        self::assertSame('IngestAnalyticsEventInput', $wire['__className__']);
        self::assertSame('product_viewed', $wire['name']);
        self::assertSame('track', $wire['kind']);
        self::assertSame('anon-browser', $wire['anonymousId']);
        self::assertSame('php', $wire['platform']);
        self::assertSame('development', $wire['environment']);
        self::assertNotEmpty($wire['eventId']);
        self::assertNotEmpty($wire['sessionId']);
        self::assertNotEmpty($wire['timestamp']);
        self::assertIsString($wire['propertiesJson']);
        $props = json_decode((string) $wire['propertiesJson'], true);
        self::assertIsArray($props);
        self::assertSame('123', $props['product_id']);
        self::assertSame(129.99, $props['price']);
    }

    public function testTrackWithOnlyUserIdUsesUserIdAsAnonymousId(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics);

        $client->setUser('user_123');
        $client->analytics->track('signed_in');
        $client->flush();

        $wire = $analytics->allEvents()[0]->toWire();
        self::assertSame('user_123', $wire['userId']);
        self::assertSame('user_123', $wire['anonymousId']);
    }

    public function testPerCallOptionsForwardBrowserIds(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics);

        $client->analytics->track('checkout_started', ['value' => 10], [
            'anonymousId' => 'from-browser',
            'userId' => 'user_checkout',
            'sessionId' => 'sess-override',
            'utmSource' => 'google',
        ]);
        $client->flush();

        $wire = $analytics->allEvents()[0]->toWire();
        self::assertSame('from-browser', $wire['anonymousId']);
        self::assertSame('user_checkout', $wire['userId']);
        self::assertSame('sess-override', $wire['sessionId']);
        self::assertSame('google', $wire['utmSource']);
    }

    public function testPerCallOptionsForwardParsedContextAndEndUserAgent(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1)';

        $client->analytics->track('checkout_started', ['value' => 10], [
            'anonymousId' => 'from-browser',
            'userId' => 'user_checkout',
            'sessionId' => 'sess-override',
            'browserName' => 'Chrome',
            'browserVersion' => '126',
            'device' => 'desktop',
            'timezone' => 'Pacific/Auckland',
        ]);
        $client->flush();

        $wire = $analytics->allEvents()[0]->toWire();
        self::assertSame('Chrome', $wire['browserName']);
        self::assertSame('126', $wire['browserVersion']);
        self::assertSame('desktop', $wire['device']);
        self::assertSame('Pacific/Auckland', $wire['timezone']);
        self::assertSame('Mozilla/5.0 (compatible; Googlebot/2.1)', $wire['userAgent']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testIdentifySetsUserForLaterErrors(): void
    {
        $events = new FakeTransport();
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, [], $events);

        $client->analytics->identify('user_123', ['plan' => 'team'], [
            'anonymousId' => 'anon-1',
        ]);
        $client->captureMessage('later error', 'error');
        $client->flush();

        $identify = $analytics->allEvents()[0]->toWire();
        self::assertSame('identify', $identify['kind']);
        self::assertSame('$identify', $identify['name']);
        self::assertSame('user_123', $identify['userId']);
        self::assertSame('anon-1', $identify['anonymousId']);
        $traits = json_decode((string) $identify['propertiesJson'], true);
        self::assertSame('team', $traits['plan']);

        $error = $events->allEvents()[0];
        self::assertSame('user_123', $error->userId);
        self::assertSame('anon-1', $error->anonymousId);
    }

    public function testPageIsExplicitAndNotAutocaptured(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, ['anonymousId' => 'anon-1']);
        $client->flush();
        self::assertSame(0, $analytics->batchCount());

        $client->analytics->page();
        $client->flush();

        $wire = $analytics->allEvents()[0]->toWire();
        self::assertSame('page', $wire['kind']);
        self::assertSame('$pageview', $wire['name']);
    }

    public function testResetClearsIdentityWithoutCookies(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, [
            'userId' => 'init-user',
            'anonymousId' => 'init-anon',
            'sessionId' => 'init-sess',
        ]);

        $client->analytics->identify('user_123', [], ['anonymousId' => 'anon-2']);
        $beforeSession = $client->getIdentity()->sessionId;
        $client->analytics->reset();

        self::assertNull($client->getIdentity()->userId);
        self::assertNull($client->getIdentity()->anonymousId);
        self::assertNotSame($beforeSession, $client->getIdentity()->sessionId);

        $client->analytics->track('after_reset');
        $client->flush();
        self::assertCount(1, $analytics->allEvents());
        self::assertSame('$identify', $analytics->allEvents()[0]->name);
    }

    public function testMissingAnalyticsScopeDisablesOnlyAnalytics(): void
    {
        $analytics = new FakeAnalyticsTransport();
        $analytics->failWith = new TransportException(
            'Talaria analytics/ingestBatch failed: HTTP 400',
            400,
            className: 'ApiUnauthorizedException',
            retry: false,
            bodyMessage: 'API key lacks required scope: analyticsWrite',
        );
        $events = new FakeTransport();
        $client = $this->client($analytics, ['anonymousId' => 'anon-1'], $events);

        $client->analytics->track('first');
        $client->analytics->track('second');
        $client->captureMessage('still events', 'info');
        $client->flush();

        self::assertSame(1, $analytics->attempts);
        self::assertTrue($client->isAnalyticsIngestDisabled());
        self::assertFalse($client->isEventsIngestDisabled());
        self::assertFalse($client->isSpansIngestDisabled());
        self::assertSame(1, $events->batchCount());
    }

    public function testPermanentIngestErrorDisablesAnalyticsToo(): void
    {
        $events = new FakeTransport();
        $events->failWith = new TransportException(
            'Talaria events/ingestBatch failed: HTTP 400',
            400,
            className: 'ApiUnauthorizedException',
            retry: false,
            bodyMessage: 'Invalid API key',
        );
        $analytics = new FakeAnalyticsTransport();
        $client = $this->client($analytics, ['anonymousId' => 'anon-1'], $events);

        $client->captureMessage('first');
        $client->flush();
        $client->analytics->track('should drop');
        $client->flush();

        self::assertTrue($client->isEventsIngestDisabled());
        self::assertTrue($client->isSpansIngestDisabled());
        self::assertTrue($client->isAnalyticsIngestDisabled());
        self::assertSame(0, $analytics->attempts);
    }

    public function testEventsAndSpansStampAnonymousId(): void
    {
        $events = new FakeTransport();
        $spans = new FakeSpanTransport();
        $analytics = new FakeAnalyticsTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'enableTracing' => true,
            'tracesSampleRate' => 1.0,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
            'anonymousId' => 'anon-shared',
            'userId' => 'user-shared',
            'sessionId' => 'sess-shared',
        ], $events, spanTransport: $spans, analyticsTransport: $analytics);

        $tx = $client->startTransaction('GET /checkout');
        $tx->end();
        $client->captureMessage('hello');
        $client->flush();

        $eventWire = $events->allEvents()[0]->toWire();
        self::assertSame('anon-shared', $eventWire['anonymousId']);
        self::assertSame('user-shared', $eventWire['userId']);
        self::assertSame('sess-shared', $eventWire['sessionId']);

        $spanWire = $spans->allSpans()[0]->toWire();
        self::assertSame('anon-shared', $spanWire['anonymousId']);
        self::assertSame('user-shared', $spanWire['userId']);
        self::assertSame('sess-shared', $spanWire['sessionId']);
    }

    public function testTransportPostsServerpodEnvelope(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);

        $transport = new AnalyticsTransport(
            'https://api.example.com/',
            'tal_live_abc',
            2.0,
            $http,
        );

        $transport->sendBatch([
            new AnalyticsEvent(
                name: 'product_viewed',
                kind: AnalyticsEventKind::Track,
                anonymousId: 'anon-1',
                sessionId: 'sess-1',
                timestamp: RuntimeContext::isoTimestamp(),
                eventId: 'evt-1',
                userId: 'user-1',
                platform: 'php',
                environment: 'production',
                propertiesJson: '{"price":129.99}',
            ),
        ]);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.com/analytics/ingestBatch', (string) $request->getUri());
        self::assertSame('tal_live_abc', $request->getHeaderLine('X-API-Key'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('IngestAnalyticsEventBatchInput', $body['input']['__className__']);
        self::assertCount(1, $body['input']['events']);
        self::assertSame('IngestAnalyticsEventInput', $body['input']['events'][0]['__className__']);
        self::assertSame('product_viewed', $body['input']['events'][0]['name']);
        self::assertSame('track', $body['input']['events'][0]['kind']);
        self::assertSame('anon-1', $body['input']['events'][0]['anonymousId']);
        self::assertSame('{"price":129.99}', $body['input']['events'][0]['propertiesJson']);
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    private function client(
        FakeAnalyticsTransport $analytics,
        array $extraOptions = [],
        ?FakeTransport $events = null,
    ): TalariaClient {
        return new TalariaClient(array_merge([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $extraOptions), $events ?? new FakeTransport(), analyticsTransport: $analytics);
    }
}
