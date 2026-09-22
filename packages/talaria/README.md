# talaria/talaria

[![Latest Version](https://img.shields.io/packagist/v/talaria/talaria.svg)](https://packagist.org/packages/talaria/talaria)
[![PHP Version](https://img.shields.io/packagist/php-v/talaria/talaria.svg)](https://packagist.org/packages/talaria/talaria)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official PHP SDK for [Talaria](https://www.newtalaria.com) — capture exceptions and application logs into triageable issues, with optional APM spans.

Events queue in memory and flush on batch size, max age, or process shutdown. Fingerprinting stays on the server. A permanent ingest error (`retry: false`, such as an invalid API key) stops further event, span, and analytics sends for this process; quota and 5xx do not. Missing `analyticsWrite` disables analytics only.

Building a **Silverstripe** site? Install [`talaria/silverstripe`](https://packagist.org/packages/talaria/silverstripe) instead. Building **Laravel**? Install [`talaria/laravel`](https://packagist.org/packages/talaria/laravel). Both pull this package and wire the framework for you.

**Packagist:** [talaria/talaria](https://packagist.org/packages/talaria/talaria) · **Docs:** [PHP SDK](https://www.newtalaria.com/docs/sdk/php) · [Silverstripe](https://www.newtalaria.com/docs/sdk/silverstripe) · [Laravel](https://www.newtalaria.com/docs/sdk/laravel) · [Dashboard](https://one.newtalaria.com)

## Install

PHP 8.1+.

```bash
composer require talaria/talaria
```

## Initialize

Create a client key under **Project settings → Client keys** (`tal_live_…`). Default keys include `eventsWrite`, `spansWrite`, `replaysWrite`, and `analyticsWrite`.

```php
use Talaria\Talaria;

Talaria::init([
    'dsn' => getenv('TALARIA_DSN') ?: 'https://api.newtalaria.com',
    'apiKey' => getenv('TALARIA_API_KEY'),
    'environment' => getenv('TALARIA_ENVIRONMENT') ?: 'production', // staging | development
    'release' => getenv('TALARIA_RELEASE') ?: null,
    'commitSha' => getenv('TALARIA_COMMIT_SHA') ?: null,
    'minLevel' => 'warning',
    'sampleRate' => 1.0,
    'enableTracing' => false,
    'tracesSampleRate' => 0.1,
    'enableAnalytics' => true,
    'tags' => [
        'service' => 'api',
        'platform' => 'php',
    ],
]);
```

Never hardcode keys. Prefer environment variables or your secret store.

| Concern | Production default |
| --- | --- |
| Log volume | `minLevel: 'warning'` |
| Identity | Set `userId` when you know the signed-in user |
| Tracing | Leave `enableTracing` off until you want APM |
| Analytics | Core default on (explicit `track` / `identify`). Silverstripe/Laravel adapters may default off |
| Shutdown | Leave `defaultIntegrations: true` so uncaught errors flush |
| Invalid key | The SDK stops sending for this PHP process after a permanent ingest error |

On Octane, Horizon, or queue workers call `Talaria::resetRequestState()` between jobs or requests. The Laravel adapter does this for you.

## Capture exceptions

```php
try {
    charge();
} catch (Throwable $e) {
    Talaria::captureException($e, [
        'tags' => ['feature' => 'checkout', 'component' => 'stripe'],
        'extra' => ['cart_id' => 'cart_01H…'],
    ]);
    throw $e;
}
```

`captureException` always sends severity `error`. `captureMessage` defaults to `info`.

## Scoped logging

Prefer a scoped logger in application code. `Talaria\Logger` implements PSR-3. Level methods wrap `captureMessage`; use `captureException` for errors.

```php
$logger = Talaria::logger([
    'tags' => ['feature' => 'checkout', 'operation' => 'pay'],
]);

$logger->info('Checkout opened'); // filtered when minLevel is warning
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

// Child scopes inherit tags. Assigned minLevel may raise or lower the floor
// unless enforceDefaultLevel is true.
$payments = $logger->child([
    'tags' => ['component' => 'payments'],
    'minLevel' => 'error',
]);
$payments->error('Charge failed');
```

| Method | Severity sent |
| --- | --- |
| `debug` / `info` / `warning` / `error` / `fatal` | same name |
| `warn` | `warning` |
| `log($level, $message)` | mapped severity |
| `captureException` | `error` |

### Tags vs extra

- **`tags`** — low-cardinality filters (`feature`, `operation`, `component`). These become dashboard facets.
- **`extra`** — high-cardinality diagnostics (`cart_id`, payloads). Do not put unique ids in tags.

On PSR-3 calls, pass throwables under `exception` so Talaria captures a real stack:

```php
$logger->error('Checkout failed', [
    'exception' => $e,
    'tags' => ['feature' => 'checkout'],
    'order_id' => '123',
]);
```

### Level hierarchy

Client `minLevel` is the default/root. A scoped logger may assign a different floor (more or less verbose). Set `enforceDefaultLevel: true` to restore a hard floor (`max(root, scope)`).

Gates run in order. Filtered calls are quiet no-ops.

1. **`minLevel`** — default/root severity
2. **`sampleRate`** — fraction of eligible **events** to enqueue (not traces)
3. **`beforeSend`** — return `null` to drop, or a mutated event

```php
Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => getenv('TALARIA_API_KEY'),
    'environment' => 'production',
    'minLevel' => 'warning',
    'beforeSend' => static function (array $event): ?array {
        if (str_contains(strtolower((string) ($event['message'] ?? '')), 'password')) {
            return null;
        }
        return $event;
    },
    'loggers' => [
        'checkout' => [
            'minLevel' => 'info',
            'tags' => ['area' => 'checkout'],
        ],
    ],
]);
```

Full hierarchy notes: [docs/logging-levels.md](docs/logging-levels.md).

## User and request context

```php
Talaria::getClient()?->setUser('user_01H…');
Talaria::getClient()?->setAnonymousId($browserAnonymousId);
Talaria::getClient()?->addProcessor(static function (array $bag): array {
    return [
        'tags' => [
            'host' => $_SERVER['HTTP_HOST'] ?? 'cli',
        ],
    ];
});
```

## Breadcrumbs

A ring buffer of 50 breadcrumbs is attached on error events, with `traceId` / `spanId` when a span is in scope.

```php
Talaria::addBreadcrumb([
    'type' => 'user',
    'category' => 'ui',
    'message' => 'Tapped Pay',
    'level' => 'info',
]);
```

## Tracing (APM)

Turn tracing on in the project first, then set `enableTracing: true` or `tracesSampleRate > 0`. Successful transactions default to a 10% sample; **error** transactions are always sent. Child spans are stored, not billed — only sampled root transactions count toward the plan quota.

```php
Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => getenv('TALARIA_API_KEY'),
    'environment' => 'production',
    'enableTracing' => true,
    'tracesSampleRate' => 0.1,
]);

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

| Helper | Role |
| --- | --- |
| `Talaria\Tracing\GuzzleMiddleware` | Outbound HTTP + W3C `traceparent` |
| `Talaria\Tracing\Psr15Middleware` / `IncomingHttp::startTransaction()` | Incoming HTTP SERVER transaction |
| `Talaria\Tracing\TracingPdo` / `TracingMysqli` | SQL CLIENT spans (N+1 stays visible) |
| `Talaria\Tracing\RedisInstrumentation::wrap()` | Redis CLIENT spans |
| `Talaria::getTraceparent()` | Active header so you can continue a browser trace |

When tracing is off, `startTransaction` / `startSpan` return no-ops.

## Analytics

PHP does not autocapture and has no cookie jar. Pass `userId` and/or `anonymousId` (browser ids forwarded on checkout APIs). `identify` / `setUser` stamp later errors and spans. Set `enableAnalytics: false` to no-op the facade (Silverstripe does this until YAML `enableAnalytics` is on).

```php
$talaria = Talaria::getClient();

$talaria->analytics->track('product_viewed', ['product_id' => '123', 'price' => 129.99], [
    'anonymousId' => $browserAnonymousId, // required unless setUser / setAnonymousId / identify
]);
$talaria->analytics->identify('user_123', ['plan' => 'team']);
$talaria->analytics->page(); // optional explicit; never automatic
$talaria->analytics->reset();
```

Missing `analyticsWrite` on the key disables analytics only. A permanent ingest error (`retry: false`) still stops events, spans, and analytics for this process.

## Shutdown

```php
Talaria::flush();
Talaria::close();
```

Call `flush` from process shutdown (and long CLI scripts) so the last batch leaves the queue.

## Public API

`Talaria::init`, `logger`, `withTags`, level helpers, `captureException`, `captureMessage`, `addBreadcrumb`, `startTransaction`, `startSpan`, `getTraceparent`, `analytics`, `resetRequestState`, `flush`, `close`.

`Talaria\TalariaClient` is the DI-friendly client.

## What this package does not do

- Fingerprints — computed on the server
- Silverstripe or Laravel hooks — use the adapter packages
- Host / Kubernetes metrics or continuous profiling
- One HTTP call per log — ingest is always batched

## License

MIT
