# Changelog

All notable changes to `talaria/silverstripe` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-09-24

### Changed

- Default `browserSdkVersion` is **0.3.0**. With `enableAnalytics` on, `@newtalaria/browser` 0.3.0 also sends click and scroll heatmaps.

## [1.1.0] - 2026-09-22

### Added

- YAML / `TALARIA_ENABLE_ANALYTICS` `enableAnalytics` (default off). Enables PHP `Talaria::analytics()` and passes `enableAnalytics` to the public-page browser inject. CMS admin is not opted in.
- Request middleware binds the current Member as `userId` when analytics is on so server-side `track` works for logged-in visitors.

### Changed

- Default `browserSdkVersion` is **0.2.2** (`Talaria.analytics` on `@newtalaria/browser`).
- Requires `talaria/talaria` ^1.1.1.

## [1.0.0] - 2026-09-21

### Added

- First release as `talaria/silverstripe` (Silverstripe 4.13+ / 5 / 6 vendormodule) depending on `talaria/talaria`.
- Injector + Monolog (1/2 vs 3 handlers), browser SDK inject, request/MySQL/Guzzle/QueuedJobs tracing YAML.

[1.1.1]: https://packagist.org/packages/talaria/silverstripe#1.1.1
[1.1.0]: https://packagist.org/packages/talaria/silverstripe#1.1.0
[1.0.0]: https://packagist.org/packages/talaria/silverstripe#1.0.0
