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
TALARIA_RELEASE=1.4.2
```

The API key decides the environment. Do not call `Talaria::init()` yourself. Tracing and analytics follow Project settings.

HTML responses on the `web` middleware group load `@newtalaria/browser` from jsDelivr (`TALARIA_BROWSER_SDK_VERSION`, default `0.5.3`). Replay, heatmaps, and web vitals start only when the project allows them. Set `TALARIA_BROWSER=false` to skip the script. `TALARIA_BROWSER_DSN` and `TALARIA_BROWSER_API_KEY` override the PHP DSN and key for the browser only.

## License

MIT
