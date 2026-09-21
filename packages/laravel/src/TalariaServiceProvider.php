<?php

declare(strict_types=1);

namespace Talaria\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Talaria\Config;
use Talaria\Laravel\Http\Middleware\TracingMiddleware;
use Talaria\Laravel\Integration\AuthIntegration;
use Talaria\Laravel\Integration\ConsoleIntegration;
use Talaria\Laravel\Integration\ExceptionIntegration;
use Talaria\Laravel\Integration\HorizonIntegration;
use Talaria\Laravel\Integration\HttpClientIntegration;
use Talaria\Laravel\Integration\LivewireIntegration;
use Talaria\Laravel\Integration\OctaneIntegration;
use Talaria\Laravel\Integration\QueryIntegration;
use Talaria\Laravel\Integration\QueueIntegration;
use Talaria\Laravel\Integration\RequestIntegration;
use Talaria\Laravel\Log\TalariaLogChannel;
use Talaria\Talaria;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanTransportInterface;
use Talaria\Transport\NullTransport;
use Talaria\Transport\TransportInterface;

final class TalariaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/talaria.php', 'talaria');

        $this->app->singleton(TalariaClient::class, function ($app): TalariaClient {
            $config = $app['config']->get('talaria', []);
            $dsn = is_string($config['dsn'] ?? null) ? $config['dsn'] : '';
            $apiKey = is_string($config['api_key'] ?? null) ? $config['api_key'] : '';
            $tags = [
                'platform' => 'php',
                'runtime' => 'laravel',
            ];
            $service = is_string($config['service'] ?? null) ? $config['service'] : '';
            if ($service !== '') {
                $tags['service'] = $service;
            }
            if ($this->app->version()) {
                $tags['runtime_version'] = $this->app->version();
            }

            $options = [
                'dsn' => $dsn !== '' ? $dsn : 'https://disabled.invalid',
                'apiKey' => str_starts_with($apiKey, 'tal_live_')
                    ? $apiKey
                    : 'tal_live_disabled_placeholder_key_xxxxxxxxxxxx',
                'environment' => is_string($config['environment'] ?? null) ? $config['environment'] : 'production',
                'release' => is_string($config['release'] ?? null) && $config['release'] !== ''
                    ? $config['release']
                    : null,
                'commitSha' => is_string($config['commit_sha'] ?? null) && $config['commit_sha'] !== ''
                    ? $config['commit_sha']
                    : null,
                'minLevel' => is_string($config['min_level'] ?? null) ? $config['min_level'] : 'warning',
                'sampleRate' => isset($config['sample_rate']) ? (float) $config['sample_rate'] : 1.0,
                'enableTracing' => (bool) ($config['enable_tracing'] ?? false),
                'tracesSampleRate' => isset($config['traces_sample_rate'])
                    ? (float) $config['traces_sample_rate']
                    : 0.1,
                'defaultIntegrations' => false,
                'tags' => Config::normalizeTags($tags),
            ];

            $transport = $app->bound(TransportInterface::class)
                ? $app->make(TransportInterface::class)
                : null;
            $spanTransport = $app->bound(SpanTransportInterface::class)
                ? $app->make(SpanTransportInterface::class)
                : null;

            if ($transport === null && ($dsn === '' || !str_starts_with($apiKey, 'tal_live_'))) {
                $transport = new NullTransport();
                $options['sampleRate'] = 0.0;
            }

            $client = new TalariaClient($options, $transport, spanTransport: $spanTransport);
            Talaria::setClient($client);

            return $client;
        });

        $this->app->alias(TalariaClient::class, 'talaria');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/talaria.php' => $this->app->configPath('talaria.php'),
            ], 'talaria-config');
        }

        $this->app->make(TalariaClient::class);

        $this->app->afterResolving('log', function ($log): void {
            if (is_object($log) && method_exists($log, 'extend')) {
                $log->extend('talaria', function ($app, array $config) {
                    return (new TalariaLogChannel())($config);
                });
            }
        });

        $this->app->make(ExceptionIntegration::class)->register();
        $this->app->make(RequestIntegration::class)->register();
        $this->app->make(QueryIntegration::class)->register();
        $this->app->make(QueueIntegration::class)->register();
        $this->app->make(HttpClientIntegration::class)->register();
        $this->app->make(ConsoleIntegration::class)->register();
        $this->app->make(AuthIntegration::class)->register();
        $this->app->make(OctaneIntegration::class)->register();
        $this->app->make(HorizonIntegration::class)->register();
        $this->app->make(LivewireIntegration::class)->register();

        $this->app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e): void {
                    $this->app->make(TalariaClient::class)->captureException($e);
                });
            }
        });

        if (method_exists($this->app, 'make') && $this->app->bound(\Illuminate\Contracts\Http\Kernel::class)) {
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(TracingMiddleware::class);
            }
        }
    }
}
