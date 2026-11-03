<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Factory;
use Talaria\Config as SdkConfig;
use Talaria\TalariaClient;

/**
 * Builds a shared TalariaClient from YAML / environment config.
 *
 * Fail safe: a missing or invalid TALARIA_API_KEY, or any fault while building
 * the client, gives a disabled no-op client. The site keeps serving; Talaria is
 * off and one warning goes to the PHP error log. (Not the Silverstripe logger:
 * that logger's Talaria handler is built from this factory.)
 */
final class InjectorFactory implements Factory
{
    private static ?TalariaClient $client = null;

    /**
     * The parameter stays untyped and the return type is the concrete client, so
     * this matches the Factory interface on every supported framework major:
     * Silverstripe 4/5 `create($service, array $params = [])` and Silverstripe 6
     * `create(string $service, array $params = []): ?object`.
     *
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = []): TalariaClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        try {
            self::$client = self::build();
        } catch (\Throwable $e) {
            self::$client = new TalariaClient(new SdkConfig(
                [],
                '[Talaria] Silverstripe client setup failed: ' . $e->getMessage() . ' Talaria is disabled.',
            ));
        }

        return self::$client;
    }

    private static function build(): TalariaClient
    {
        $options = Config::toClientOptions();

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

        $client = new TalariaClient($options);
        if (!$client->isEnabled()) {
            return $client;
        }
        $client->addProcessor(static function (array $bag): array {
            $tags = RequestTags::collect();
            $http = self::$client?->getTracer()->requestAttributes() ?? [];
            foreach (['http.route', 'http.request.method'] as $key) {
                if (isset($http[$key]) && is_string($http[$key]) && $http[$key] !== '') {
                    $tags[$key] = $http[$key];
                }
            }

            return ['tags' => $tags];
        });

        return $client;
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
