# talaria/silverstripe

[![Latest Version](https://img.shields.io/packagist/v/talaria/silverstripe.svg)](https://packagist.org/packages/talaria/silverstripe)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Silverstripe 4.13+ / 5 / 6 adapter for [Talaria](https://www.newtalaria.com).

**Docs:** [Silverstripe guide](https://www.newtalaria.com/docs/sdk/silverstripe) · [Project configuration](https://www.newtalaria.com/docs/configuration)

## Install

PHP 8.1+ and Silverstripe 4.13, 5, or 6.

```bash
composer require talaria/silverstripe
```

The DSN defaults to `https://ingest.newtalaria.com`. Set the API key, then flush:

```bash
TALARIA_API_KEY="tal_live_…"
# Optional. CMS and public pages use this project. PHP keeps TALARIA_API_KEY.
# TALARIA_BROWSER_API_KEY="tal_live_…"
```

The API key decides the environment.

```bash
vendor/bin/sake dev/build flush=1
```

Optional YAML for log level, service name, and browser inject is in the [Silverstripe guide](https://www.newtalaria.com/docs/sdk/silverstripe). Tracing and analytics follow Project settings.

## SiteHost

`vendor/bin/talaria-sitehost install` reads `talaria/sitehost/monitors.json` and reconciles one crontab block and the supervisord probe. A second run reuses the stored ping tokens. Cron jobs still ping with `curl`. The SiteHost deploy action runs this command.

## License

MIT
