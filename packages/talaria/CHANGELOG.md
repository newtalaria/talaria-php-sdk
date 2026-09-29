# Changelog

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
