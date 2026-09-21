# talaria/laravel

[![Latest Version](https://img.shields.io/packagist/v/talaria/laravel.svg)](https://packagist.org/packages/talaria/laravel)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Laravel 10 / 11 / 12 adapter for [Talaria](https://www.newtalaria.com). Installs [`talaria/talaria`](https://packagist.org/packages/talaria/talaria) and wires exceptions, logs, and optional tracing — requests, Eloquent, queues, HTTP client, Artisan, Octane, Horizon, and Livewire.

**Packagist:** [talaria/laravel](https://packagist.org/packages/talaria/laravel) · **Docs:** [Laravel guide](https://www.newtalaria.com/docs/sdk/laravel) · [Dashboard](https://one.newtalaria.com)

## Install

PHP 8.1+ and Laravel 10, 11, or 12.

```bash
composer require talaria/laravel
php artisan vendor:publish --tag=talaria-config
```

The service provider and `Talaria` facade are auto-discovered. Publishing config is optional — environment variables are enough to start.

Create a client key under **Project settings → Client keys** (`tal_live_…`):

```env
TALARIA_DSN=https://api.newtalaria.com
TALARIA_API_KEY=tal_live_…
TALARIA_ENVIRONMENT=production
TALARIA_RELEASE=1.4.2
# TALARIA_COMMIT_SHA=
# TALARIA_SERVICE="${APP_NAME}"
# TALARIA_MIN_LEVEL=warning
# TALARIA_ENABLE_TRACING=false
# TALARIA_TRACES_SAMPLE_RATE=0.1
```

Missing DSN or key disables ingest safely. Do not call `Talaria::init()` yourself — the provider owns the client.

## First exception

Reportable exceptions are captured automatically. Your Laravel exception handler still runs.

```php
// This reaches Talaria as an error event with a stack.
throw new RuntimeException('Checkout charge failed');
```

Open **Issues** on [one.newtalaria.com](https://one.newtalaria.com) and filter by this project’s environment.

## Capture from your code

```php
use Talaria\Laravel\Facade\Talaria;

Talaria::captureMessage('Checkout opened');

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

Or the core scoped logger:

```php
use Talaria\Talaria;

Talaria::logger(['tags' => ['feature' => 'checkout']])->warn('Payment method missing');
```

## What is wired

| Surface | Behavior |
| --- | --- |
| Exceptions | Laravel `reportable` → `captureException` |
| HTTP | SERVER transaction; `http.route` from the route name or URI template |
| Eloquent / DB | `QueryExecuted` → CLIENT/db spans (N+1 stays visible) |
| Queue | Producer on dispatch; CONSUMER transaction per job; flush + reset after each job |
| HTTP client | CLIENT spans + W3C `traceparent` (never wraps Talaria ingest) |
| Artisan | Span per command |
| Log channel | `Log::channel('talaria')->error('…')` |
| Auth | Optional `userId` from the current guard (`TALARIA_IDENTIFY_USERS`) |
| Octane | `resetRequestState()` on receive; `flush()` on terminate |
| Horizon | Same queue hooks + `messaging.system=horizon` |
| Livewire | Component-name child spans under the HTTP transaction |

Octane, Horizon, and Livewire are optional. They activate when those packages are installed.

## Tracing

Off until `TALARIA_ENABLE_TRACING=true` or `TALARIA_TRACES_SAMPLE_RATE` is greater than `0`. Head sampling: **100% of error transactions**, default **10%** of successful.

```env
TALARIA_ENABLE_TRACING=true
TALARIA_TRACES_SAMPLE_RATE=0.1
```

Child spans are stored, not billed — only sampled root transactions count toward the plan quota.

## Log channel

```php
// config/logging.php
'talaria' => [
    'driver' => 'talaria',
],
```

```php
use Illuminate\Support\Facades\Log;

Log::channel('talaria')->error('Fulfilment delayed', [
    'tags' => ['feature' => 'fulfilments'],
    'order_id' => '42',
]);
```

## Workers

Queue workers, Horizon, and Octane reuse a long-lived PHP process. The adapter calls `flush()` and `resetRequestState()` for you after each job or request. If you start the core client yourself in a custom worker, call those methods between units of work.

## License

MIT
