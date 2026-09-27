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
    'environment' => getenv('TALARIA_ENVIRONMENT') ?: 'production',
    'release' => getenv('TALARIA_RELEASE') ?: null,
    'minLevel' => 'warning',
]);
```

Tracing, analytics, and sample rates follow Project settings. See the [PHP guide](https://www.newtalaria.com/docs/sdk/php).

## License

MIT
