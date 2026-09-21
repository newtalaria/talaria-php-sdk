<?php

declare(strict_types=1);

return [
    'dsn' => env('TALARIA_DSN', 'https://api.newtalaria.com'),
    'api_key' => env('TALARIA_API_KEY'),
    'environment' => env('TALARIA_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('TALARIA_RELEASE'),
    'commit_sha' => env('TALARIA_COMMIT_SHA'),
    'service' => env('TALARIA_SERVICE', env('APP_NAME', 'laravel')),
    'min_level' => env('TALARIA_MIN_LEVEL', 'warning'),
    'sample_rate' => (float) env('TALARIA_SAMPLE_RATE', 1.0),
    'enable_tracing' => filter_var(env('TALARIA_ENABLE_TRACING', false), FILTER_VALIDATE_BOOLEAN),
    'traces_sample_rate' => (float) env('TALARIA_TRACES_SAMPLE_RATE', 0.1),
    'identify_users' => filter_var(env('TALARIA_IDENTIFY_USERS', true), FILTER_VALIDATE_BOOLEAN),
];
