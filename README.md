# Talaria PHP SDKs

Official Composer packages for [Talaria](https://www.newtalaria.com) — exceptions, application logs, and optional APM traces.

| If you are building… | Packagist | Docs |
| --- | --- | --- |
| A Silverstripe 4.13+ / 5 / 6 site | [`talaria/silverstripe`](https://packagist.org/packages/talaria/silverstripe) | [Guide](https://www.newtalaria.com/docs/sdk/silverstripe) |
| A Laravel 10 / 11 / 12 app | [`talaria/laravel`](https://packagist.org/packages/talaria/laravel) | [Guide](https://www.newtalaria.com/docs/sdk/laravel) |
| Plain PHP, Symfony, or another framework | [`talaria/talaria`](https://packagist.org/packages/talaria/talaria) | [Guide](https://www.newtalaria.com/docs/sdk/php) |

Each adapter depends on the core package. You do not need to require `talaria/talaria` yourself when using Silverstripe or Laravel.

```bash
composer require talaria/silverstripe
composer require talaria/laravel
composer require talaria/talaria
```

Source: [github.com/newtalaria/talaria-php-sdk](https://github.com/newtalaria/talaria-php-sdk) · Dashboard: [one.newtalaria.com](https://one.newtalaria.com)

Packagist reads a root `composer.json`, so each package is mirrored:

| Package | Packagist | Mirror |
| --- | --- | --- |
| Core | [talaria/talaria](https://packagist.org/packages/talaria/talaria) | [newtalaria/talaria-php](https://github.com/newtalaria/talaria-php) |
| Silverstripe | [talaria/silverstripe](https://packagist.org/packages/talaria/silverstripe) | [newtalaria/talaria-silverstripe](https://github.com/newtalaria/talaria-silverstripe) |
| Laravel | [talaria/laravel](https://packagist.org/packages/talaria/laravel) | [newtalaria/talaria-laravel](https://github.com/newtalaria/talaria-laravel) |

## What you get

- Batched ingest to `POST /v1/events`, `POST /v1/spans`, and `POST /v1/analytics` as `{"events":[…]}` or `{"spans":[…]}`
- `Authorization: Bearer` with a checksummed `tal_sk_` key. The host is `https://ingest-<cell>.newtalaria.com` unless `TALARIA_URL` or the `url` option is set
- Browser inject uses `browser_api_key` / `TALARIA_BROWSER_API_KEY` (`tal_pk_` only)
- Server-side fingerprinting — the SDK never computes issue groups
- Tracing, analytics, and sample rates follow [project configuration](https://www.newtalaria.com/docs/configuration)
- PSR-3 `Talaria\Logger` on the core package; framework adapters wire exceptions, HTTP, and databases for you

## Develop

```bash
cd packages/talaria && composer update && composer test && composer phpstan
cd ../silverstripe && composer config repositories.talaria path ../talaria && composer update && composer test
cd ../laravel && composer config repositories.talaria path ../talaria && composer update && composer test
```

## License

MIT
