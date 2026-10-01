<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Integration\UncaughtExceptionDump;
use Talaria\SeverityLevel;
use Talaria\TalariaClient;

final class ExceptionDedupeTest extends TestCase
{
    public function testSameThrowableIsCapturedOnce(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $exception = new \RuntimeException('db down');

        $client->captureException($exception);
        $client->captureException($exception);

        self::assertSame(1, $transport->batchCount());
        self::assertSame('db down', $transport->batches[0][0]->message);
        self::assertTrue($client->hasCapturedException());
    }

    public function testDifferentThrowablesBothSend(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $previous = new \RuntimeException('root');
        $wrapper = new \RuntimeException('wrap', 0, $previous);

        $client->captureException($wrapper);
        $client->captureException($previous);

        self::assertSame(2, $transport->batchCount());
        self::assertSame('wrap', $transport->batches[0][0]->message);
        self::assertSame('root', $transport->batches[1][0]->message);
    }

    public function testResetRequestStateAllowsTheSameThrowableAgain(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $exception = new \RuntimeException('db down');

        $client->captureException($exception);
        $client->resetRequestState();
        $client->captureException($exception);

        self::assertSame(2, $transport->batchCount());
    }

    public function testEngineDumpMessageIsDroppedAfterAnException(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->captureException(new \RuntimeException('db down'));
        $client->captureMessage(self::engineDump(), SeverityLevel::Error);
        $client->captureMessage('Fatal Error (E_ERROR): ' . self::engineDump(), SeverityLevel::Fatal);

        self::assertSame(1, $transport->batchCount());
        self::assertSame('db down', $transport->batches[0][0]->message);
    }

    public function testEngineDumpMessageIsKeptWhenNothingWasCaptured(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->captureMessage(self::engineDump(), SeverityLevel::Error);

        self::assertSame(1, $transport->batchCount());
        self::assertSame(self::engineDump(), $transport->batches[0][0]->message);
    }

    public function testShutdownSkipsEngineFatalAfterCapture(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->captureException(new \Error('property used before initialization'));

        $client->captureUnhandledError(self::engineDump(), E_ERROR, '/app/src/Task.php', 80);

        self::assertSame(1, $transport->batchCount());
        self::assertSame('property used before initialization', $transport->batches[0][0]->message);
    }

    public function testShutdownStillSendsEngineFatalWhenNothingWasCaptured(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->captureUnhandledError(self::engineDump(), E_ERROR, '/app/src/Task.php', 80);

        self::assertSame(1, $transport->batchCount());
        self::assertSame('ErrorException', $transport->batches[0][0]->title);
        self::assertFalse($transport->batches[0][0]->exception['values'][0]['mechanism']['handled']);
    }

    public function testShutdownStillSendsRealFatals(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $message = 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)';

        $client->captureException(new \RuntimeException('db down'));
        $client->captureUnhandledError($message, E_ERROR, '/app/src/Task.php', 80);
        $client->captureMessage($message, SeverityLevel::Fatal);

        self::assertSame(3, $transport->batchCount());
        self::assertSame($message, $transport->batches[1][0]->message);
        self::assertSame($message, $transport->batches[2][0]->message);
    }

    public function testPsr3UncaughtLogIsUnhandledAndDeduped(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $logger = $client->logger();
        $exception = new \RuntimeException('boom');

        $logger->error(
            'Uncaught Exception RuntimeException: "boom" at file.php line 10',
            ['exception' => $exception],
        );
        $logger->error('boom', ['exception' => $exception]);

        self::assertSame(1, $transport->batchCount());
        $event = $transport->batches[0][0];
        self::assertSame('boom', $event->message);
        self::assertFalse($event->exception['values'][0]['mechanism']['handled']);
    }

    public function testPsr3CaughtExceptionStaysHandled(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);

        $client->logger()->error('payment failed', [
            'exception' => new \RuntimeException('payment failed'),
        ]);

        self::assertTrue($transport->batches[0][0]->exception['values'][0]['mechanism']['handled']);
    }

    public function testEngineFatalDetector(): void
    {
        self::assertTrue(UncaughtExceptionDump::isEngineFatal(self::engineDump()));
        self::assertTrue(UncaughtExceptionDump::isEngineFatal('Fatal Error (E_ERROR): ' . self::engineDump()));
        self::assertFalse(UncaughtExceptionDump::isEngineFatal(
            'Uncaught Exception RuntimeException: "boom" at file.php line 10',
        ));
        self::assertFalse(UncaughtExceptionDump::isEngineFatal(
            'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
        ));
        self::assertTrue(UncaughtExceptionDump::logMarksUnhandled('Uncaught Exception Error: "x" at f line 1'));
        self::assertFalse(UncaughtExceptionDump::logMarksUnhandled('Fatal Error (E_ERROR): Uncaught Error: x'));
    }

    private function client(FakeTransport $transport): TalariaClient
    {
        return new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
            'flushIntervalMs' => 60_000,
        ], $transport);
    }

    private static function engineDump(): string
    {
        return "Uncaught Error: Typed property Foo::\$bar must not be accessed before initialization in /app/src/Foo.php:80\n"
            . "Stack trace:\n"
            . "#0 /app/public/index.php(24): main()\n"
            . "  thrown";
    }
}
