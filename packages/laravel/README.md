# talaria/laravel

[![Latest Version](https://img.shields.io/packagist/v/talaria/laravel.svg)](https://packagist.org/packages/talaria/laravel)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Laravel 10 / 11 / 12 adapter for [Talaria](https://www.newtalaria.com).

**Docs:** [Laravel guide](https://www.newtalaria.com/docs/sdk/laravel) · [Project configuration](https://www.newtalaria.com/docs/configuration)

## Install

PHP 8.1+ and Laravel 10, 11, or 12.

```bash
composer require talaria/laravel
```

The published config defaults the DSN to `https://ingest.newtalaria.com`. Set the API key:

```env
TALARIA_API_KEY=tal_live_…
TALARIA_ENVIRONMENT=production
TALARIA_RELEASE=1.4.2
```

Do not call `Talaria::init()` yourself. Tracing and analytics follow Project settings.

## License

MIT
