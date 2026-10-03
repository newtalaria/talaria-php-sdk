# Changelog

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
