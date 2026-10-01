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
composer require talaria/talaria
```

## Initialize

```php
use Talaria\Talaria;

Talaria::init([
    'dsn' => 'https://ingest.newtalaria.com',
    'apiKey' => getenv('TALARIA_API_KEY'),
    'release' => getenv('TALARIA_RELEASE') ?: null,
    'minLevel' => 'warning',
]);
```

The API key decides the environment. Tracing, analytics, and sample rates follow Project settings. See the [PHP guide](https://www.newtalaria.com/docs/sdk/php).

## Feature flags

```php
Talaria::flags()->setContext(userId: $userId);
$on = Talaria::flags()->boolVariation('new-checkout', false);
```

Evaluations are cached for the PHP request (or until TTL on long-lived workers). Call `loadDefinitions()` with a server API key that has `flags:definitions` for local evaluation without per-request RTT.

**LaunchDarkly:** dual-running Talaria flags and LaunchDarkly in the same process is fine. Prefer one source of truth per flag key (or distinct namespaces) so evaluations do not collide. Mobile clients may not refresh while backgrounded.

## License

MIT
