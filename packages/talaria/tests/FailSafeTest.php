<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\Config;
use Talaria\Credential;
use Talaria\FailSafe;
use Talaria\TalariaClient;
use Talaria\Transport\ServerpodHttpTransport;
use Talaria\Transport\TransportInterface;

final class FailSafeTest extends TestCase
{
    private const OLD_KEY = 'tal_live_g2WhG4abcdefghijklmnopqrstuvwxyz0123456789';

    private string $log = '';

    private string|false $previousLog = false;

    protected function setUp(): void
    {
        FailSafe::reset();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'talaria-failsafe');
        $this->previousLog = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        @unlink($this->log);
        FailSafe::reset();
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badOptions(): iterable
    {
        yield 'missing key' => [[], 'No API key'];
        yield 'empty key' => [['apiKey' => ''], 'No API key'];
        yield 'blank key' => [['apiKey' => '   '], 'No API key'];
        yield 'non-string key' => [['apiKey' => 123], 'No API key'];
        yield 'malformed tal_live_ key' => [['apiKey' => self::OLD_KEY], 'is invalid'];
        yield 'wrong checksum' => [['apiKey' => substr(Credential::example(), 0, -6) . 'AAAAAA'], 'checksum'];
        yield 'self cell without url' => [['apiKey' => Credential::example('sk', 'test', 'self'), 'url' => ''], 'no ingest host'];
        yield 'bad beforeSend option' => [['apiKey' => Credential::example(), 'beforeSend' => 'not-a-function'], 'beforeSend'];
    }

    /**
     * @dataProvider badOptions
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badOptions')]
    public function testBadInitGivesDisabledNoOpClientAndOneWarning(array $options, string $reason): void
    {
        $transport = new FakeTransport();
        $spans = new FakeSpanTransport();
        $analytics = new FakeAnalyticsTransport();
        $client = new TalariaClient(
            $options + ['url' => 'https://api.example.com', 'maxBatchSize' => 1, 'flushIntervalMs' => 0],
            $transport,
            spanTransport: $spans,
            analyticsTransport: $analytics,
        );

        self::assertFalse($client->isEnabled());
        self::assertSame('', $client->getConfig()->apiKey);
        self::assertSame('', $client->getConfig()->url);

        $client->captureMessage('hello');
        $client->captureException(new \RuntimeException('boom'));
        $client->info('log line');
        $client->logger('scoped')->error('scoped line');
        $span = $client->startTransaction('GET /');
        $client->startSpan('child')->end();
        $span->end();
        $client->analytics->track('signup', ['plan' => 'free']);
        $client->recordFinishedQuery('SELECT 1', 'mysql', 1.0);
        self::assertSame('ok', $client->recordQuery('SELECT 1', 'mysql', static fn (): string => 'ok'));
        self::assertFalse($client->flags->boolVariation('x', false));
        self::assertSame([], $client->checkIn('nightly', 'ok'));
        $client->reportClient(['discarded' => []]);
        self::assertNull($client->getTraceparent());
        $client->flush();
        $client->close();

        self::assertSame(0, $transport->attempts);
        self::assertSame([], $spans->batches);
        self::assertSame(0, $client->queueSize());
        self::assertSame(0, $client->spanQueueSize());
        self::assertSame(0, $client->analyticsQueueSize());

        $lines = $this->logLines();
        self::assertCount(1, $lines, implode("\n", $lines));
        self::assertStringContainsString('Talaria is disabled', $lines[0]);
        self::assertStringContainsString($reason, $lines[0]);
    }

    public function testWarningShowsAtMostEightKeyCharacters(): void
    {
        new TalariaClient(['apiKey' => self::OLD_KEY]);

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('tal_live…', $log);
        self::assertStringNotContainsString('tal_live_', $log);
        self::assertStringNotContainsString('g2WhG4', $log);
    }

    public function testWarningIsLoggedOncePerProcess(): void
    {
        new TalariaClient(['apiKey' => self::OLD_KEY]);
        new TalariaClient(['apiKey' => '']);
        new TalariaClient(['apiKey' => self::OLD_KEY]);

        self::assertCount(1, $this->logLines());
    }

    public function testOnWarningReceivesTheWarningInsteadOfErrorLog(): void
    {
        $seen = [];
        new TalariaClient([
            'apiKey' => self::OLD_KEY,
            'onWarning' => static function (string $message) use (&$seen): void {
                $seen[] = $message;
            },
        ]);

        self::assertCount(1, $seen);
        self::assertStringContainsString('Talaria is disabled', $seen[0]);
        self::assertSame([], $this->logLines());
    }

    public function testThrowingOnWarningFallsBackToErrorLog(): void
    {
        new TalariaClient([
            'apiKey' => self::OLD_KEY,
            'onWarning' => static function (string $message): void {
                throw new \LogicException('logger down');
            },
        ]);

        self::assertCount(1, $this->logLines());
    }

    public function testDisabledConfigIgnoresRemoteConfig(): void
    {
        $client = new TalariaClient(['apiKey' => '']);
        $client->getConfig()->applySdkDocument([
            'schemaVersion' => 1,
            'active' => true,
            'tracing' => ['enabled' => true, 'tracesSampleRate' => 1.0],
            'analytics' => ['enabled' => true],
            'flags' => ['enabled' => true],
        ]);

        self::assertFalse($client->getConfig()->enableTracing);
        self::assertFalse($client->getConfig()->enableAnalytics);
        self::assertFalse($client->getConfig()->enableFlags);
    }

    public function testValidKeyStaysEnabled(): void
    {
        $config = new Config(['apiKey' => Credential::example()]);

        self::assertTrue($config->enabled);
        self::assertNull($config->disabledReason);
        self::assertSame([], $this->logLines());
    }

    /**
     * @return iterable<string, array{0: Response|ConnectException}>
     */
    public static function transportFailures(): iterable
    {
        yield 'HTTP 401' => [new Response(401, ['Content-Type' => 'application/problem+json'], '{"code":"unauthorized","message":"bad key"}')];
        yield 'HTTP 404' => [new Response(404, [], 'Method not found')];
        yield 'HTTP 500' => [new Response(500, [], 'oops')];
        yield 'timeout' => [new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://api.example.com/v1/events'))];
    }

    /**
     * @dataProvider transportFailures
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transportFailures')]
    public function testTransportFailuresNeverReachTheCaller(Response|ConnectException $failure): void
    {
        $responses = array_fill(0, 8, $failure);
        $http = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'http_errors' => false,
        ]);
        $transport = new ServerpodHttpTransport('https://api.example.com', Credential::example(), 1.0, $http);
        $client = new TalariaClient(
            [
                'apiKey' => Credential::example(),
                'url' => 'https://api.example.com',
                'defaultIntegrations' => false,
                'remoteConfig' => false,
                'maxBatchSize' => 1,
            ],
            $transport,
            spanTransport: new FakeSpanTransport(),
            analyticsTransport: new FakeAnalyticsTransport(),
        );

        $client->captureMessage('one');
        $client->captureException(new \RuntimeException('two'));
        self::assertSame([], $client->checkIn('nightly', 'ok'));
        $client->reportClient(['discarded' => []]);
        $client->flush();

        self::assertTrue($client->isEnabled());
        // At most one line each: permanent-disable notice, events, monitors, client-reports.
        self::assertLessThanOrEqual(4, count($this->logLines()));
    }

    public function testNonTransportExceptionFromTransportNeverReachesTheCaller(): void
    {
        $transport = new class () implements TransportInterface {
            public int $attempts = 0;

            public function sendBatch(array $events): void
            {
                $this->attempts++;
                throw new \InvalidArgumentException('json_encode error: Malformed UTF-8');
            }
        };
        $client = new TalariaClient(
            [
                'apiKey' => Credential::example(),
                'url' => 'https://api.example.com',
                'defaultIntegrations' => false,
                'remoteConfig' => false,
                'maxBatchSize' => 1,
            ],
            $transport,
            spanTransport: new FakeSpanTransport(),
            analyticsTransport: new FakeAnalyticsTransport(),
        );

        $client->captureMessage('one');
        $client->captureMessage('two');
        $client->flush();

        self::assertSame(2, $transport->attempts);
        // Rate-limited: one line per signal per interval.
        self::assertCount(1, $this->logLines());
    }

    public function testKeyPreviewNeverExceedsEightCharacters(): void
    {
        self::assertSame('', FailSafe::keyPreview('  '));
        self::assertSame('tal_sk', FailSafe::keyPreview('tal_sk'));
        self::assertSame('tal_sk_p…', FailSafe::keyPreview(Credential::example('sk', 'prod')));
    }

    /**
     * @return list<string>
     */
    private function logLines(): array
    {
        $raw = (string) file_get_contents($this->log);

        return array_values(array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => str_contains($line, '[Talaria]'),
        ));
    }
}
