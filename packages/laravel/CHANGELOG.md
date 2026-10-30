# Changelog

## 3.0.1 - 2026-10-30

- The injected browser script loads `@newtalaria/browser` 0.6.0 by default (`TALARIA_BROWSER_SDK_VERSION`). 0.6.0 is the browser SDK that takes the `tal_pk_` key and the key-derived ingest host that 3.0.0 sends.
- The README describes the current release with no upgrade notes.

## 3.0.0 - 2026-10-30

- Breaking: requires `talaria/talaria` ^3.0. Keys, ingest host, routes, and auth follow the TIS hard cut in the core 3.0.0 changelog.
- Breaking: `TALARIA_API_KEY` is a secret `tal_sk_` key. A malformed key fails fast. An empty key leaves the client disabled.
- Breaking: the `dsn` config key and `TALARIA_DSN` are removed. Use `url` / `TALARIA_URL` only to override the host derived from the key's cell.
- Breaking: `browser_dsn` and `TALARIA_BROWSER_DSN` are removed. The injected browser key is `browser_api_key` / `TALARIA_BROWSER_API_KEY` and must be a publishable `tal_pk_` key. The server key is never injected, so the script is skipped without a valid `tal_pk_` key. Republish or update `config/talaria.php`.

## 2.0.1 - 2026-10-03

- HTML responses on the `web` middleware group load `@newtalaria/browser` 0.5.3 from jsDelivr. Set `TALARIA_BROWSER=false` to skip. Replay, heatmaps, web vitals, and analytics still follow project config.

## 2.0.0 - 2026-10-01

- Breaking: remove the `environment` config key (`TALARIA_ENVIRONMENT` / `APP_ENV`). The API key decides the environment.
- Requires `talaria/talaria` ^2.0.0.
- Eloquent queries use the shared SQL span helper. Identical SQL under one parent rolls up, and `withoutQuerySpans` turns those spans off for one run.

## 1.2.1 - 2026-09-27

- Package docs point at the marketing guides. The published config still defaults the DSN to `https://ingest.newtalaria.com`.

## 1.2.0 - 2026-09-27

- Requires `talaria/talaria` ^1.2.0. Tracing and analytics follow the project policy document.

## 1.1.0

- Previous release on Packagist.
