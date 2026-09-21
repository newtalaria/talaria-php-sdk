# Changelog

All notable changes to `talaria/laravel` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-21

### Added

- Facade `Talaria::analytics()` forwards the core server-side analytics client.

## [1.0.0] - 2026-09-21

### Added

- Laravel 10 / 11 / 12 adapter on `talaria/talaria`.
- Auto-discovery service provider, published `config/talaria.php`, and `Talaria` facade.
- Exception reporting, request SERVER spans, Eloquent/query CLIENT spans, queue producer/consumer, HTTP client + `traceparent`, Artisan spans, `talaria` log channel, and optional Auth `userId`.
- Octane request/task/tick reset + flush; Horizon attributes; Livewire component child spans.

[1.1.0]: https://packagist.org/packages/talaria/laravel#1.1.0
[1.0.0]: https://packagist.org/packages/talaria/laravel#1.0.0
