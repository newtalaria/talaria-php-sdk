# talaria/talaria

Official PHP SDK for [Talaria](https://www.newtalaria.com) — capture exceptions, application logs, and (optionally) traces. Framework-agnostic core. For first-class adapters see [`talaria/silverstripe`](https://packagist.org/packages/talaria/silverstripe) and [`talaria/laravel`](https://packagist.org/packages/talaria/laravel).

Events are **queued in memory** and sent with batch ingest when the buffer hits a size limit, exceeds a max age, or the request shuts down. Fingerprinting stays on the server.

Docs: [PHP SDK guide](https://www.newtalaria.com/docs/sdk/php) · Dashboard: [one.newtalaria.com](https://one.newtalaria.com)

## Install

```bash
composer require talaria/talaria
```

## Initialize

Create a client key under **Project settings → Client keys** (`tal_live_…`).

```php
use Talaria\Talaria;

Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => 'tal_live_…',
    'environment' => 'production', // staging | development also accepted
    'release' => '1.4.2',
    'commitSha' => getenv('TALARIA_COMMIT_SHA') ?: null,
    'minLevel' => 'warning',
    'sampleRate' => 1.0,
    'enableTracing' => false,
    'tracesSampleRate' => 0.1,
    'tags' => [
        'service' => 'api',
        'platform' => 'php',
    ],
]);
```

| Concern | Production default |
| --- | --- |
| Log volume | `minLevel: 'warning'` |
| Identity | Set `userId` when you know the signed-in user |
| Tracing | Leave `enableTracing` off until you want APM |
| Shutdown | Leave `defaultIntegrations: true` so uncaught errors flush |
| Invalid key | The SDK stops sending events and spans for this PHP process after a permanent ingest error (`retry: false`) |

On Octane, Horizon, or queue workers call `Talaria::resetRequestState()` between jobs or requests (the Laravel adapter does this for you).

## Capture

```php
$logger = Talaria::logger([
    'tags' => ['feature' => 'checkout', 'operation' => 'pay'],
]);

$logger->warn('Payment method missing');

try {
    charge();
} catch (Throwable $e) {
    $logger->captureException($e, [
        'tags' => ['component' => 'stripe'],
        'extra' => ['cart_id' => 'abc123'],
    ]);
    throw $e;
}
```

`Talaria\Logger` implements PSR-3. Level gates, scoped loggers, and `enforceDefaultLevel` are documented in [docs/logging-levels.md](docs/logging-levels.md).

## Tracing

Off until `enableTracing: true` or `tracesSampleRate > 0`. Head sampling: **100% of error transactions**, default **10%** of successful.

```php
$tx = Talaria::startTransaction('GET /checkout');
try {
    $span = Talaria::startSpan('SELECT', 'client', [
        'db.system.name' => 'mysql',
        'db.operation.name' => 'SELECT',
    ]);
    $span->end();
    $tx->setStatus('ok');
} finally {
    $tx->end();
}
```

Helpers (never wrap the SDK’s own ingest Guzzle clients):

- `Talaria\Tracing\GuzzleMiddleware` — outbound HTTP + `traceparent`
- `Talaria\Tracing\Psr15Middleware` / `IncomingHttp::startTransaction()`
- `Talaria\Tracing\TracingPdo` / `TracingMysqli`
- `Talaria\Tracing\RedisInstrumentation::wrap()`

## Public API

`Talaria::init`, `logger`, `withTags`, level helpers, `captureException`, `captureMessage`, `addBreadcrumb`, `startTransaction`, `startSpan`, `getTraceparent`, `resetRequestState`, `flush`, `close`.

`Talaria\TalariaClient` is the DI-friendly client. `Talaria\Client` is a deprecated alias.

## License

MIT
