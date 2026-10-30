# Changelog

## 3.0.1 - 2026-10-30

- Docs only: the README and `docs/logging-levels.md` describe the current release with no upgrade or migration notes. No code changes.

## 3.0.0 - 2026-10-30

Major release for the Talaria Ingest Spec (TIS) hard cut. There are no fallbacks for the 2.x key, host, or route formats.

- Breaking: API keys are checksummed `tal_(pk|sk)_(prod|stg|test|dev)_<cell>_<43 base62><6 CRC32 base62>`. `tal_live_` keys are rejected, and a bad grammar or checksum throws at init. Servers use a secret `tal_sk_` key. A publishable `tal_pk_` key logs one warning: ingest and `POST /v1/flags/evaluate` work, but monitors, releases, and `GET /v1/flags` need a secret key.
- Breaking: the `dsn` and `baseUrl` init options and `TALARIA_DSN` are removed. The ingest host comes from the key's cell (`https://ingest-<cell>.newtalaria.com`). The `url` option or `TALARIA_URL` is the only override. A `self` cell needs a URL. `Config::$baseUrl` is now `Config::$url`.
- Breaking: requests send `Authorization: Bearer` instead of `X-API-Key`.
- Breaking: the /v1 routes replace the Serverpod RPC routes. Batches go to `POST /v1/events`, `/v1/spans`, and `/v1/analytics` as plain JSON with no `input` wrapper. Remote config is `GET /v1/sdk-config`. Flags use `GET /v1/flags` and `POST /v1/flags/evaluate`. Discard reports go to `POST /v1/client-reports`.
- Breaking: errors are parsed as `application/problem+json`. `IngestError` exposes `code` and `requestId`, and `TransportException` exposes `problemCode` and `requestId`, instead of `className`.
- Breaking: `Talaria::monitor` checks in with `POST /v1/monitors/{slug}/check-ins`. Monitor tokens are `tal_mon_<cell>_…`.
- `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` are read into tags. Code and `TALARIA_*` values win over `OTEL_*`.

## 2.1.0 - 2026-10-09

- `Talaria::monitor` sends `in_progress`, runs the callback, then `ok` or `error`, and posts `monitors/checkIn`. The API key needs `monitors:write`.

## 2.0.0 - 2026-10-01

- Breaking: `environment` is no longer an init option and is not sent on events, spans, or analytics. The API key decides the environment.

## 1.2.4 - 2026-09-30

- Feature flags: `Talaria::flags()` with `boolVariation` / `stringVariation` / `jsonVariation`, `setContext`, in-process cache, TTL refresh, and `loadDefinitions` for local evaluation.
- Stamp `flag.*` tags on captured events. Respect `flags.enabled` from `sdk/getConfig`.
- Can run alongside LaunchDarkly; Talaria flags join the same session/error graph.

## 1.2.3 - 2026-09-29

- Identical SQL under one parent is one span with `db.query.count` and `db.query.duration_sum_ms`. The span stays the slowest execution. Queries of 200ms or more, and failed queries, stay their own spans.
- A transaction stores at most 200 spans and keeps 32 slots for non-SQL spans. The root records `dropped_span_count` when a span is dropped.
- `withoutQuerySpans` and `setRecordQuerySpans(false)` turn automatic SQL spans off for one run.
- Query breadcrumbs use at most 15 of the 50 breadcrumb slots, so application breadcrumbs stay when a job runs thousands of queries.

## 1.2.2 - 2026-09-29

- Report each uncaught exception once. A second capture of the same throwable is dropped, and PHP's "Uncaught … thrown" shutdown fatal is not sent again as a separate event.

## 1.2.1 - 2026-09-27

- Package docs point at the marketing guides.

## 1.2.0 - 2026-09-27

- Tracing, analytics, and the event sample rate come from `POST /sdk/getConfig`.
- Init options `enableTracing`, `tracesSampleRate`, `enableAnalytics`, and `sampleRate` no longer change that behavior.
- With no cached policy, the SDK sends errors only. A disabled signal stops that signal. A rejected key stops the process.

## 1.1.1

- Previous release on Packagist.
