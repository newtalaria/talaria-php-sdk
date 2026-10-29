<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Factory;
use Talaria\Config as SdkConfig;
use Talaria\TalariaClient;
use Talaria\Transport\NullTransport;

/**
 * Builds a shared TalariaClient from YAML / environment config.
 *
 * When DSN/API key are missing, returns a disabled client so Install / flush
 * does not crash before env vars are set.
 */
final class InjectorFactory implements Factory
{
    private static ?TalariaClient $client = null;

    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = [])
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $options = Config::toClientOptions();
        $url = is_string($options['url'] ?? null) ? $options['url'] : '';
        $apiKey = is_string($options['apiKey'] ?? null) ? $options['apiKey'] : '';

        $validPrefixes = ['tal_prod_', 'tal_stg_', 'tal_test_', 'tal_dev_'];
        $hasValidPrefix = false;
        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($apiKey, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }

        if ($url === '' || $apiKey === '' || !$hasValidPrefix) {
            error_log('[Talaria] Silverstripe client disabled — set TALARIA_URL and TALARIA_API_KEY with valid prefix (tal_prod_, tal_stg_, tal_test_, or tal_dev_).');
            self::$client = new TalariaClient(
                [
                    'url' => 'https://disabled.invalid',
                    'apiKey' => 'tal_dev_disabled_placeholder_key_xxxxxxxxxxxx',
                    'sampleRate' => 0.0,
                    'defaultIntegrations' => false,
                ],
                new NullTransport(),
            );

            return self::$client;
        }

        $options['tags'] = array_merge(
            is_array($options['tags'] ?? null) ? $options['tags'] : [],
            [
                'platform' => 'php',
                'runtime' => 'silverstripe',
            ],
        );

        $runtimeVersion = FrameworkVersion::resolve();
        if ($runtimeVersion !== null) {
            $options['tags']['runtime_version'] = $runtimeVersion;
        }

        $options['tags'] = SdkConfig::normalizeTags($options['tags']);

        self::$client = new TalariaClient($options);
        self::$client->addProcessor(static function (array $bag): array {
            $tags = RequestTags::collect();
            $http = self::$client?->getTracer()->requestAttributes() ?? [];
            foreach (['http.route', 'http.request.method'] as $key) {
                if (isset($http[$key]) && is_string($http[$key]) && $http[$key] !== '') {
                    $tags[$key] = $http[$key];
                }
            }

            return ['tags' => $tags];
        });

        return self::$client;
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$client?->close();
        self::$client = null;
    }
}
