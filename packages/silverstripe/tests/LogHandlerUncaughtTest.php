<?php

declare(strict_types=1);

namespace Talaria\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Talaria\Event;
use Talaria\SilverStripe\Handlers\TalariaLogHandlerMonolog3;
use Talaria\TalariaClient;
use Talaria\Transport\TransportInterface;

final class LogHandlerUncaughtTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/handlers/TalariaLogHandlerMonolog3.php';
    }

    public function testUncaughtLogIsOneUnhandledEvent(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);
        $handler = new TalariaLogHandlerMonolog3($client, Level::Debug);
        $exception = new \RuntimeException('boom');

        $handler->handle($this->record(
            'Uncaught Exception RuntimeException: "boom" at file.php line 10',
            $exception,
        ));
        $handler->handle($this->record('boom', $exception));

        self::assertCount(1, $transport->events);
        self::assertSame('boom', $transport->events[0]->message);
        self::assertFalse($transport->events[0]->exception['values'][0]['mechanism']['handled']);
    }

    public function testCaughtExceptionLogStaysHandled(): void
    {
        $transport = new RecordingTransport();
        $client = $this->client($transport);
        $handler = new TalariaLogHandlerMonolog3($client, Level::Debug);

        $handler->handle($this->record('payment failed', new \RuntimeException('payment failed')));

        self::assertCount(1, $transport->events);
        self::assertSame('payment failed', $transport->events[0]->message);
        self::assertTrue($transport->events[0]->exception['values'][0]['mechanism']['handled']);
    }

    private function client(RecordingTransport $transport): TalariaClient
    {
        return new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
            'flushIntervalMs' => 60_000,
        ], $transport);
    }

    private function record(string $message, \Throwable $exception): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Error,
            $message,
            ['exception' => $exception],
        );
    }
}

final class RecordingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function sendBatch(array $events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }
}
