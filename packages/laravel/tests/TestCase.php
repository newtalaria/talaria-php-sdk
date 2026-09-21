<?php

declare(strict_types=1);

namespace Talaria\Laravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Talaria\Laravel\TalariaServiceProvider;
use Talaria\Talaria;
use Talaria\Tracing\SpanTransportInterface;
use Talaria\Transport\TransportInterface;

abstract class TestCase extends BaseTestCase
{
    protected RecordingTransport $transport;

    protected RecordingSpanTransport $spans;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->spans = new RecordingSpanTransport();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Talaria::reset();
        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [TalariaServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app->instance(TransportInterface::class, $this->transport);
        $app->instance(SpanTransportInterface::class, $this->spans);
        $app['config']->set('talaria.dsn', 'https://api.example.com');
        $app['config']->set('talaria.api_key', 'tal_live_testkeytestkeytestkeytestkey123456');
        $app['config']->set('talaria.environment', 'testing');
        $app['config']->set('talaria.enable_tracing', true);
        $app['config']->set('talaria.traces_sample_rate', 1.0);
        $app['config']->set('talaria.min_level', 'debug');
        $app['config']->set('talaria.service', 'demo');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/ok', static fn () => 'ok')->name('checkout.show');
        $router->get('/boom', static function (): void {
            throw new \RuntimeException('checkout failed');
        })->name('checkout.boom');
    }
}
