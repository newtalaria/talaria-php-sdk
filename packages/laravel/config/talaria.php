<?php

declare(strict_types=1);

return [
    'dsn' => env('TALARIA_DSN', 'https://ingest.newtalaria.com'),
    'api_key' => env('TALARIA_API_KEY'),
    'release' => env('TALARIA_RELEASE'),
    'commit_sha' => env('TALARIA_COMMIT_SHA'),
    'service' => env('TALARIA_SERVICE', env('APP_NAME', 'laravel')),
    'min_level' => env('TALARIA_MIN_LEVEL', 'warning'),
    'sample_rate' => (float) env('TALARIA_SAMPLE_RATE', 1.0),
    'enable_tracing' => filter_var(env('TALARIA_ENABLE_TRACING', false), FILTER_VALIDATE_BOOLEAN),
    'traces_sample_rate' => (float) env('TALARIA_TRACES_SAMPLE_RATE', 0.1),
    'identify_users' => filter_var(env('TALARIA_IDENTIFY_USERS', true), FILTER_VALIDATE_BOOLEAN),

    // Inject @newtalaria/browser into HTML responses. Replay and heatmaps
    // still wait for the project config. Set TALARIA_BROWSER=false to skip.
    'browser' => filter_var(env('TALARIA_BROWSER', true), FILTER_VALIDATE_BOOLEAN),
    'browser_sdk_version' => env('TALARIA_BROWSER_SDK_VERSION', '0.5.3'),
    'browser_dsn' => env('TALARIA_BROWSER_DSN'),
    'browser_api_key' => env('TALARIA_BROWSER_API_KEY'),
];
