<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Context\RuntimeContext;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;
use Talaria\Tracing\Tracer;

final class TracerTest extends TestCase
{
    public function testIdenticalQuerySpansRollUpUnderTheParent(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $root = $client->startTransaction('GET /products', SpanKind::Server);
        $sql = 'SELECT * FROM "Product" WHERE "ID" = ?';
        for ($i = 0; $i < 12; $i++) {
            $query = $client->startSpan('SELECT', SpanKind::Client, [
                'db.system.name' => 'mysql',
                'db.operation.name' => 'SELECT',
                'db.query.text' => $sql,
            ]);
            $query->setStatus(SpanStatus::Ok);
            $query->end();
        }
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        $all = $spans->allSpans();
        self::assertCount(2, $all);
        self::assertSame('GET /products', $all[0]->name);
        $query = $all[1];
        $wire = $query->toWire();
        self::assertSame('SELECT', $query->name);
        self::assertSame('mysql', $wire['attributes']['db.system.name']);
        self::assertSame($sql, $wire['attributes']['db.query.text']);
        self::assertSame('12', $wire['attributes']['db.query.count']);
        self::assertSame($all[0]->spanId, $wire['parentSpanId']);
        self::assertArrayNotHasKey('dropped_span_count', $all[0]->toWire()['attributes'] ?? []);
    }

    public function testInterleavedQueriesKeepLaterPhaseSpans(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /dev/tasks/SyncShopifyDataTask', SpanKind::Server);
        $import = $client->startSpan('shopify.import_products', SpanKind::Internal);
        foreach (['SELECT File', 'SELECT SiteTree', 'SELECT Shopify_ProductVariant'] as $name) {
            for ($i = 0; $i < 12; $i++) {
                $query = $client->startSpan($name, SpanKind::Client, [
                    'db.system.name' => 'mysql',
                    'db.query.text' => $name,
                ]);
                $query->setStatus(SpanStatus::Ok);
                $query->end();
            }
        }
        $import->end();
        $collections = $client->startSpan('shopify.import_collections', SpanKind::Internal);
        $collections->end();
        $collects = $client->startSpan('shopify.import_collects', SpanKind::Internal);
        $collects->end();
        $root->end();
        $client->flush();

        $names = array_map(static fn ($span) => $span->name, $spans->allSpans());
        self::assertContains('shopify.import_products', $names);
        self::assertContains('shopify.import_collections', $names);
        self::assertContains('shopify.import_collects', $names);
        $queries = array_values(array_filter(
            $spans->allSpans(),
            static fn ($span) => str_starts_with($span->name, 'SELECT'),
        ));
        self::assertCount(3, $queries);
        foreach ($queries as $query) {
            self::assertSame('12', $query->toWire()['attributes']['db.query.count']);
        }
        self::assertArrayNotHasKey('dropped_span_count', $spans->allSpans()[0]->toWire()['attributes'] ?? []);
    }

    public function testSlowAndFailedQueriesStayTheirOwnSpans(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /products', SpanKind::Server);
        $sql = 'SELECT File';

        $fast = $client->startSpan('SELECT File', SpanKind::Client, ['db.query.text' => $sql]);
        $fast->setStatus(SpanStatus::Ok);
        $fast->end();

        $endMs = (int) floor(microtime(true) * 1000);
        $slow = $client->startSpan('SELECT File', SpanKind::Client, ['db.query.text' => $sql]);
        $slow->reviseWindow(RuntimeContext::isoFromUnixMs($endMs - 250), RuntimeContext::isoFromUnixMs($endMs));
        $slow->setStatus(SpanStatus::Ok);
        $slow->end(RuntimeContext::isoFromUnixMs($endMs));

        $failed = $client->startSpan('SELECT File', SpanKind::Client, ['db.query.text' => $sql]);
        $failed->setStatus(SpanStatus::Error, 'deadlock');
        $failed->end();

        $again = $client->startSpan('SELECT File', SpanKind::Client, ['db.query.text' => $sql]);
        $again->setStatus(SpanStatus::Ok);
        $again->end();

        $root->end();
        $client->flush();

        $queries = array_values(array_filter(
            $spans->allSpans(),
            static fn ($span) => $span->name === 'SELECT File',
        ));
        self::assertCount(3, $queries);
        self::assertSame('2', $queries[0]->toWire()['attributes']['db.query.count']);
        self::assertGreaterThanOrEqual(200.0, $queries[1]->durationMs());
        self::assertSame(SpanStatus::Error->value, $queries[2]->getStatus());
    }

    public function testSqlBudgetLeavesRoomForALaterPhaseSpan(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /sync', SpanKind::Server);
        for ($i = 0; $i < Tracer::MAX_SQL_SPANS + 1; $i++) {
            $query = $client->startSpan('SELECT t' . $i, SpanKind::Client, [
                'db.query.text' => 'SELECT t' . $i,
            ]);
            $query->setStatus(SpanStatus::Ok);
            $query->end();
        }
        $phase = $client->startSpan('shopify.import_collections', SpanKind::Internal);
        $phase->end();
        $root->end();
        $client->flush();

        $names = array_map(static fn ($span) => $span->name, $spans->allSpans());
        self::assertContains('shopify.import_collections', $names);
        self::assertNotContains('SELECT t' . Tracer::MAX_SQL_SPANS, $names);
        $rootWire = $spans->allSpans()[0]->toWire();
        self::assertSame('1', $rootWire['attributes']['dropped_span_count']);
    }

    public function testWithoutQuerySpansRestoresTheFlagWhenTheCallbackThrows(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('task', SpanKind::Server);
        try {
            $client->withoutQuerySpans(static function () use ($client): void {
                $client->recordQuery('SELECT File', 'mysql', static function (): void {
                    throw new \RuntimeException('sync failed');
                });
            });
            self::fail('expected the callback to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('sync failed', $e->getMessage());
        }
        self::assertTrue($client->recordsQuerySpans());
        $phase = $client->startSpan('shopify.import_collections', SpanKind::Internal);
        $phase->end();
        $root->end();
        $client->flush();

        $names = array_map(static fn ($span) => $span->name, $spans->allSpans());
        self::assertSame(['task', 'shopify.import_collections'], $names);
    }

    public function testContinuesIncomingTraceparent(): void
    {
        $previous = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        $_SERVER['HTTP_TRACEPARENT'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        try {
            $spans = new FakeSpanTransport();
            $client = $this->client($spans);
            $root = $client->startTransaction('GET /continued', SpanKind::Server);
            $child = $client->startSpan('SELECT', SpanKind::Client);
            $child->end();
            $root->end();
            $client->flush();

            $all = $spans->allSpans();
            self::assertCount(2, $all);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $all[0]->traceId);
            self::assertSame('00f067aa0ba902b7', $all[0]->toWire()['parentSpanId']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $all[1]->traceId);
            self::assertSame($all[0]->spanId, $all[1]->toWire()['parentSpanId']);
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_TRACEPARENT']);
            } else {
                $_SERVER['HTTP_TRACEPARENT'] = $previous;
            }
        }
    }

    public function testDroppedSpansAreNotSent(): void
    {
        $spans = new FakeSpanTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'enableTracing' => true,
            'tracesSampleRate' => 0.0,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], new FakeTransport(), spanTransport: $spans);
        $client->getConfig()->applySdkDocument([
            'schemaVersion' => 1,
            'active' => true,
            'tracing' => ['enabled' => true, 'tracesSampleRate' => 0.0],
        ]);

        $root = $client->startTransaction('GET /drop', SpanKind::Server);
        $child = $client->startSpan('SELECT', SpanKind::Client);
        $child->end();
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        self::assertSame(0, $spans->batchCount());
        self::assertSame(0, $client->spanQueueSize());
    }

    private function client(FakeSpanTransport $spans): TalariaClient
    {
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
            'tags' => ['service' => 'api'],
            'release' => '1.2.3',
        ], new FakeTransport(), spanTransport: $spans);
        $client->getConfig()->applySdkDocument([
            'schemaVersion' => 1,
            'active' => true,
            'tracing' => ['enabled' => true, 'tracesSampleRate' => 1.0],
        ]);

        return $client;
    }
}
