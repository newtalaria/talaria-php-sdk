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

Set a secret key. Leave `TALARIA_URL` empty to use `https://ingest-<cell>.newtalaria.com` from that key:

```env
TALARIA_API_KEY=tal_sk_prod_nz1_…
TALARIA_BROWSER_API_KEY=tal_pk_prod_nz1_…
TALARIA_RELEASE=1.4.2
```

`TALARIA_RELEASE` is optional. In GitHub Actions or GitLab the SDK sends `<ref>@<shortsha>` when it is unset. See [Releases](https://www.newtalaria.com/docs/guides/releases).

Do not call `Talaria::init()` yourself. Tracing and analytics follow Project settings. A malformed key fails fast. An empty key leaves the client disabled.

HTML responses on the `web` middleware group load `@newtalaria/browser` from jsDelivr (`TALARIA_BROWSER_SDK_VERSION`, default `0.5.3`). Replay, heatmaps, and web vitals start only when the project allows them. Set `TALARIA_BROWSER=false` to skip the script. The injected key is `browser_api_key` (`TALARIA_BROWSER_API_KEY`) and must be a `tal_pk_` key. The server `tal_sk_` key is not injected.

## License

MIT
