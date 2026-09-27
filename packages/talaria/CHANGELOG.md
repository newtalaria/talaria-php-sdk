# Changelog

## 1.2.0 - 2026-09-27

- Tracing, analytics, and the event sample rate come from `POST /sdk/getConfig`.
- Init options `enableTracing`, `tracesSampleRate`, `enableAnalytics`, and `sampleRate` no longer change that behavior.
- With no cached policy, the SDK sends errors only. A disabled signal stops that signal. A rejected key stops the process.

## 1.1.1

- Previous release on Packagist.
