<?php

declare(strict_types=1);

namespace Talaria\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Talaria\Laravel\Http\Middleware\TracingMiddleware;
use Talaria\Talaria;
use Talaria\TalariaClient;

final class LaravelPackageTest extends TestCase
{
    public function testServiceProviderBindsClientAndStaticFacade(): void
    {
        $client = $this->app->make(TalariaClient::class);
        self::assertSame($client, Talaria::getClient());
        self::assertTrue($client->getConfig()->enableTracing);
        self::assertSame('demo', $client->getConfig()->tags['service'] ?? null);
    }

    public function testExceptionHandlerReportsToTalaria(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->report(new \RuntimeException('checkout failed'));
        $this->app->make(TalariaClient::class)->flush();

        $events = $this->transport->allEvents();
        self::assertNotEmpty($events);
        self::assertSame('checkout failed', $events[0]->message);
        self::assertSame('error', $events[0]->level->value);
    }

    public function testQueryExecutedCreatesDbSpan(): void
    {
        $client = $this->app->make(TalariaClient::class);
        $tx = $client->startTransaction('GET /checkout');
        Event::dispatch(new QueryExecuted(
            'select * from "users" where "id" = 1',
            [],
            2.5,
            $this->app['db']->connection(),
        ));
        $tx->end();
        $client->flush();

        $spans = $this->spans->allSpans();
        $db = array_values(array_filter(
            $spans,
            static fn ($span) => str_starts_with($span->name, 'SELECT'),
        ));
        self::assertNotEmpty($db);
        $attrs = $db[0]->toWire()['attributes'] ?? [];
        self::assertSame('sqlite', $attrs['db.system.name'] ?? null);
        self::assertSame('2.5', $attrs['db.query.duration_ms'] ?? null);
    }

    public function testQueueJobResetsAndFlushes(): void
    {
        $job = self::fakeJob();

        Event::dispatch(new JobProcessing('sync', $job));
        self::assertNotNull($this->app->make(TalariaClient::class)->getTraceparent());
        Event::dispatch(new JobProcessed('sync', $job));

        self::assertNull($this->app->make(TalariaClient::class)->getTraceparent());
        self::assertNotEmpty($this->spans->allSpans());
    }

    public function testFailedJobCapturesException(): void
    {
        $job = self::fakeJob();

        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobFailed('sync', $job, new \RuntimeException('card declined')));
        $this->app->make(TalariaClient::class)->flush();

        $messages = array_map(static fn ($e) => $e->message, $this->transport->allEvents());
        self::assertContains('card declined', $messages);
    }

    public function testHttpMiddlewareUsesRouteName(): void
    {
        $request = Request::create('/ok', 'GET');
        $request->setRouteResolver(function () use ($request) {
            $route = $this->app['router']->getRoutes()->match($request);

            return $route;
        });

        $middleware = $this->app->make(TracingMiddleware::class);
        $middleware->handle($request, static fn () => response('ok', 200));

        $roots = array_values(array_filter(
            $this->spans->allSpans(),
            static fn ($span) => $span->parentSpanId === null && $span->name !== 'noop',
        ));
        self::assertNotEmpty($roots);
        self::assertSame('GET checkout.show', $roots[0]->name);
        self::assertSame('checkout.show', ($roots[0]->toWire()['attributes'] ?? [])['http.route'] ?? null);
    }

    public function testLogChannelForwardsToClient(): void
    {
        $this->app['config']->set('logging.channels.talaria', [
            'driver' => 'talaria',
            'name' => 'talaria',
        ]);
        Log::channel('talaria')->error('payment failed');
        $this->app->make(TalariaClient::class)->flush();

        $messages = array_map(static fn ($e) => $e->message, $this->transport->allEvents());
        self::assertContains('payment failed', $messages);
    }

    /**
     * @return object
     */
    private static function fakeJob(): object
    {
        return new class {
            public function resolveName(): string
            {
                return 'App\\Jobs\\ChargeCard';
            }

            public function getQueue(): string
            {
                return 'payments';
            }

            public function payload(): array
            {
                return [];
            }
        };
    }

    public function testOctaneIntegrationResetsBetweenRequests(): void
    {
        $client = $this->app->make(TalariaClient::class);
        $client->addBreadcrumb(['message' => 'from request A']);
        $client->setUser('user-a');
        $client->resetRequestState();
        $client->captureMessage('after', \Talaria\SeverityLevel::Error);
        $client->flush();

        $event = $this->transport->allEvents()[0];
        self::assertNull($event->breadcrumbs);
        self::assertNull($event->userId);
    }
}
