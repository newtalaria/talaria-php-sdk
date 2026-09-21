# talaria/silverstripe

[![Latest Version](https://img.shields.io/packagist/v/talaria/silverstripe.svg)](https://packagist.org/packages/talaria/silverstripe)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Silverstripe 4.13+ / 5 / 6 adapter for [Talaria](https://www.newtalaria.com). Installs [`talaria/talaria`](https://packagist.org/packages/talaria/talaria) and wires Monolog, Injector, optional browser SDK inject, and request / MySQL / Guzzle / QueuedJobs tracing.

**Docs:** [Silverstripe guide](https://www.newtalaria.com/docs/sdk/silverstripe) · [Full walkthrough](docs/silverstripe.md) · [Dashboard](https://one.newtalaria.com)

## Install

PHP 8.1+ and Silverstripe 4.13, 5, or 6.

```bash
composer require talaria/silverstripe
```

Create a client key under **Project settings → Client keys** (`tal_live_…`), then set environment variables:

```bash
TALARIA_DSN="https://api.newtalaria.com"
TALARIA_API_KEY="tal_live_…"
TALARIA_ENVIRONMENT="production"
TALARIA_RELEASE="1.2.3"
# TALARIA_COMMIT_SHA="…"
# TALARIA_ENABLE_TRACING="true"
# TALARIA_TRACES_SAMPLE_RATE="0.1"
# TALARIA_BROWSER_DSN="https://api.newtalaria.com"
```

Flush config so Injector and YAML take effect:

```bash
vendor/bin/sake dev/build flush=1
```

Missing DSN or key disables ingest safely — install and flush will not crash.

## Optional YAML

`app/_config/talaria.yml`:

```yaml
---
Name: app-talaria
After:
  - '#talaria'
  - '#talaria-browser'
---
Talaria\SilverStripe\Config:
  minLevel: warning
  service: 'my-site'
  tags:
    team: 'platform'
  enableTracing: false
  tracesSampleRate: 0.1
  enableBrowserCms: true
  enableBrowserFrontend: true
  browserSdkVersion: '0.1.25'
```

YAML `minLevel` (default **warning**) applies to the Monolog handler and the shared client. Flush again after YAML changes.

## Capture

Prefer Silverstripe’s Injector `LoggerInterface` so CMS and modules keep working if you remove Talaria later. Pass throwables under `exception` for a real stack.

```php
use Psr\Log\LoggerInterface;

final class CheckoutService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pay(string $orderId): void
    {
        $this->logger->warning('Payment method missing', [
            'tags' => ['feature' => 'checkout', 'operation' => 'pay'],
            'order_id' => $orderId,
        ]);

        try {
            $this->charge($orderId);
        } catch (Throwable $e) {
            $this->logger->error('Checkout failed', [
                'exception' => $e,
                'tags' => ['feature' => 'checkout', 'component' => 'stripe'],
                'order_id' => $orderId,
            ]);
            throw $e;
        }
    }
}
```

For scoped tags, `child`, and `captureException`, wrap the Injector `TalariaClient` — do not call `Talaria::init()` again. See [docs/silverstripe.md](docs/silverstripe.md).

## Tracing

Off until `TALARIA_ENABLE_TRACING=true` or YAML `enableTracing: true` (or a `tracesSampleRate` greater than 0). Head sampling: **100% of error transactions**, default **10%** of successful.

| Signal | Instrumentation |
| --- | --- |
| Incoming HTTP | SERVER span per request; continues W3C `traceparent` |
| MySQL | CLIENT spans per query (N+1 stays visible) |
| Outbound HTTP | Injector `GuzzleHttp\Client` + `traceparent` (ingest clients are not wrapped) |
| Queued jobs | Producer / consumer spans when `symbiote/silverstripe-queuedjobs` is installed |

## Browser JS

The same env vars can load [`@newtalaria/browser`](https://www.npmjs.com/package/@newtalaria/browser) on CMS admin and public pages. Pin with `browserSdkVersion`. Details: [client/README.md](client/README.md).

## License

MIT
