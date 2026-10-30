# talaria/talaria

[![Latest Version](https://img.shields.io/packagist/v/talaria/talaria.svg)](https://packagist.org/packages/talaria/talaria)
[![PHP Version](https://img.shields.io/packagist/php-v/talaria/talaria.svg)](https://packagist.org/packages/talaria/talaria)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Official PHP SDK for [Talaria](https://www.newtalaria.com).

**Docs:** [PHP SDK](https://www.newtalaria.com/docs/sdk/php) · [Project configuration](https://www.newtalaria.com/docs/configuration)

Building Silverstripe? Install [`talaria/silverstripe`](https://www.newtalaria.com/docs/sdk/silverstripe). Building Laravel? Install [`talaria/laravel`](https://www.newtalaria.com/docs/sdk/laravel).

## Install

PHP 8.1+.

```bash
composer require talaria/talaria:^3.0
```

## Initialize

```php
use Talaria\Talaria;

Talaria::init([
    'apiKey' => getenv('TALARIA_API_KEY'),
    'release' => getenv('TALARIA_RELEASE') ?: null,
    'minLevel' => 'warning',
]);
```

`TALARIA_API_KEY` is a secret `tal_sk_<env>_<cell>_<43 base62><6 CRC32 base62>` key. The SDK checks the grammar and checksum and refuses a bad key. A publishable `tal_pk_` key logs one warning: ingest and `POST /v1/flags/evaluate` work, but monitors, releases, and `GET /v1/flags` need a secret key.

Leave `url` unset and the SDK sends to `https://ingest-<cell>.newtalaria.com` from the key. Set `url` or `TALARIA_URL` to override. A `self` cell has no cloud host, so it fails fast until a URL is set.

Requests use `Authorization: Bearer`. Batches are plain JSON (`{"events":[…]}`, `{"spans":[…]}`) with no `input` wrapper. Errors are `application/problem+json`. Remote config is `GET /v1/sdk-config`. Discard reports go to `POST /v1/client-reports`.

Tracing, analytics, and sample rates follow Project settings. Omit `release` in GitHub Actions or GitLab and the SDK sends `<ref>@<shortsha>` from the job environment. See [Releases](https://www.newtalaria.com/docs/guides/releases).

## Feature flags

```php
Talaria::flags()->setContext(userId: $userId);
$on = Talaria::flags()->boolVariation('new-checkout', false);
```

Evaluations are cached for the PHP request (or until TTL on long-lived workers). `POST /v1/flags/evaluate` accepts a `tal_pk_` or `tal_sk_` key. Call `loadDefinitions()` with a `tal_sk_` key that has `flags:read` (`GET /v1/flags`) for local evaluation without per-request RTT.

**LaunchDarkly:** dual-running Talaria flags and LaunchDarkly in the same process is fine. Prefer one source of truth per flag key (or distinct namespaces) so evaluations do not collide. Mobile clients may not refresh while backgrounded.

## License

MIT
