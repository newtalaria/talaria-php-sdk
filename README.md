# Talaria PHP SDKs

Official Composer packages for [Talaria](https://www.newtalaria.com) — exceptions, application logs, and optional APM traces.

| If you are building… | Install | Docs |
| --- | --- | --- |
| A Silverstripe 4.13+ / 5 / 6 site | [`talaria/silverstripe`](packages/silverstripe) | [Guide](https://www.newtalaria.com/docs/sdk/silverstripe) |
| A Laravel 10 / 11 / 12 app | [`talaria/laravel`](packages/laravel) | [Guide](https://www.newtalaria.com/docs/sdk/laravel) |
| Plain PHP, Symfony, or another framework | [`talaria/talaria`](packages/talaria) | [Guide](https://www.newtalaria.com/docs/sdk/php) |

Each adapter depends on the core package. You do not need to require `talaria/talaria` yourself when using Silverstripe or Laravel.

```bash
composer require talaria/silverstripe
composer require talaria/laravel
composer require talaria/talaria
```

Source: [github.com/newtalaria/talaria-php-sdk](https://github.com/newtalaria/talaria-php-sdk) · Dashboard: [one.newtalaria.com](https://one.newtalaria.com)

## What you get

- Batched ingest to `/events/ingestBatch` and (when tracing is on) `/spans/ingestBatch`
- Project API key auth (`X-API-Key`, `tal_live_…`)
- Server-side fingerprinting — the SDK never computes issue groups
- Tracing **off** until you opt in (`enableTracing` / `tracesSampleRate`)
- PSR-3 `Talaria\Logger` on the core package; framework adapters wire exceptions, HTTP, and databases for you

## Develop

```bash
cd packages/talaria && composer update && composer test && composer phpstan
cd ../silverstripe && composer config repositories.talaria path ../talaria && composer update && composer test
cd ../laravel && composer config repositories.talaria path ../talaria && composer update && composer test
```

## License

MIT
