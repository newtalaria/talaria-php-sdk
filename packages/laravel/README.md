# talaria/laravel

Laravel 10 / 11 / 12 adapter for [Talaria](https://www.newtalaria.com). Installs [`talaria/talaria`](https://packagist.org/packages/talaria/talaria) and wires exceptions, logs, and optional tracing.

Docs: [Laravel SDK guide](https://www.newtalaria.com/docs/sdk/laravel)

## Install

```bash
composer require talaria/laravel
php artisan vendor:publish --tag=talaria-config
```

```env
TALARIA_DSN=https://api.newtalaria.com
TALARIA_API_KEY=tal_live_…
TALARIA_ENVIRONMENT=production
TALARIA_RELEASE=1.4.2
TALARIA_ENABLE_TRACING=true
TALARIA_TRACES_SAMPLE_RATE=0.1
```

The service provider is auto-discovered. Missing DSN/key disables ingest safely.

## What is wired

| Surface | Behavior |
| --- | --- |
| Exceptions | Laravel `reportable` → `captureException` (your handler still runs) |
| HTTP | SERVER transaction; `http.route` from the route name or URI template |
| Eloquent / DB | `QueryExecuted` → CLIENT/db spans (N+1 stays visible) |
| Queue | Producer on dispatch; CONSUMER transaction per job; flush + reset after each job |
| HTTP client | CLIENT spans + W3C `traceparent` (never wraps Talaria ingest) |
| Artisan | Span per command |
| Log channel | `Log::channel('talaria')->error('…')` |
| Auth | Optional `userId` from the current guard |
| Octane | `resetRequestState()` on receive; `flush()` on terminate |
| Horizon | Same queue hooks + `messaging.system=horizon` |
| Livewire | Component-name child spans under the HTTP transaction |

Tracing stays **off** until `TALARIA_ENABLE_TRACING=true` or `traces_sample_rate > 0`.

```php
use Talaria\Laravel\Facade\Talaria;

Talaria::captureMessage('Checkout opened');
```

Or the core API:

```php
use Talaria\Talaria;

Talaria::logger(['tags' => ['feature' => 'checkout']])->warn('Payment method missing');
```

## Log channel

```php
// config/logging.php
'talaria' => [
    'driver' => 'talaria',
],
```

## License

MIT
