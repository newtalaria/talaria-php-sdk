# Changelog

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
