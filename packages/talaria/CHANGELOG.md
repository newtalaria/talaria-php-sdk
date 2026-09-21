# Changelog

All notable changes to `talaria/talaria` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-21

### Added

- Server-side Analytics API (`$client->analytics->track` / `identify` / `page` / `reset`) over `POST /analytics/ingestBatch`.
- Optional `anonymousId` on event and span payloads, plus caller-overridable `sessionId` (no cookie jar).

## [1.0.0] - 2026-09-21

### Added

- First release of the framework-agnostic PHP SDK as `talaria/talaria`.
- Batched event ingest (`POST /events/ingestBatch`) and optional span ingest (`POST /spans/ingestBatch`).
- PSR-3 `Talaria\Logger`, breadcrumbs, W3C `traceparent`, PDO / mysqli / Guzzle / PSR-15 / Redis helpers.
- `TalariaClient::resetRequestState()` for long-lived workers (Octane, Horizon, queues).
- Event URL sanitization (query values stripped).

[1.1.0]: https://packagist.org/packages/talaria/talaria#1.1.0
[1.0.0]: https://packagist.org/packages/talaria/talaria#1.0.0
