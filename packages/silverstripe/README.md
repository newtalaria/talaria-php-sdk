# talaria/silverstripe

[![Latest Version](https://img.shields.io/packagist/v/talaria/silverstripe.svg)](https://packagist.org/packages/talaria/silverstripe)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Silverstripe 4.13+ / 5 / 6 adapter for [Talaria](https://www.newtalaria.com).

**Docs:** [Silverstripe guide](https://www.newtalaria.com/docs/sdk/silverstripe) · [Project configuration](https://www.newtalaria.com/docs/configuration)

## Install

PHP 8.1+ and Silverstripe 4.13, 5, or 6.

```bash
composer require talaria/silverstripe:^3.0
```

Upgrading from 2.x? 3.0 is a breaking release: `tal_live_` keys and `TALARIA_DSN` are gone. Use a `tal_sk_` key, and set `TALARIA_URL` only to override the host. See the [CHANGELOG](CHANGELOG.md). The browser key moved from YAML `browserApiKey` to `browser_api_key` and must be a `tal_pk_` key.

Set a secret key, then flush. Leave `TALARIA_URL` empty to use `https://ingest-<cell>.newtalaria.com` from that key:

```bash
TALARIA_API_KEY="tal_sk_prod_nz1_…"
# Publishable key for the injected browser SDK (config key browser_api_key).
# TALARIA_BROWSER_API_KEY="tal_pk_prod_nz1_…"
```

```bash
vendor/bin/sake dev/build flush=1
```

Optional YAML for log level, service name, and browser inject is in the [Silverstripe guide](https://www.newtalaria.com/docs/sdk/silverstripe). Tracing and analytics follow Project settings.

## SiteHost

`vendor/bin/talaria-sitehost install` reads `talaria/sitehost/monitors.json` and reconciles one crontab block and the supervisord probe. Registration uses a `tal_sk_` key on `POST /v1/monitors/{slug}/check-ins`. Cron lines check in with the stored `tal_mon_` token: `Authorization: Bearer` to `POST /v1/check-ins`. A second run reuses those tokens. The SiteHost deploy action runs this command, and that action also installs the log collector.

## License

MIT
