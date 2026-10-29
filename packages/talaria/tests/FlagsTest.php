<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Config;
use Talaria\Flags\Flags;
use Talaria\Flags\FlagsHttpClient;
use Talaria\Identity;

final class FlagsTest extends TestCase
{
    public function testRespectsFlagsEnabledPolicy(): void
    {
        $config = new Config([
            'url' => 'https://example.test',
            'apiKey' => \Talaria\Credential::example(),
        ]);
        $config->enableFlags = false;
        $flags = new Flags($config, new Identity(anonymousId: 'a1'), static fn () => null);
        self::assertFalse($flags->isEnabled());
        self::assertFalse($flags->boolVariation('demo', false));
        self::assertTrue($flags->boolVariation('demo', true));
        self::assertSame([], $flags->stampTags());
    }

    public function testRemoteEvaluateCachesAndStamps(): void
    {
        $config = new Config([
            'url' => 'https://example.test',
            'apiKey' => \Talaria\Credential::example(),
        ]);
        $config->enableFlags = true;

        $http = new class implements FlagsHttpClient {
            public int $calls = 0;
            public ?string $lastPath = null;
            /** @var array<string, mixed>|null */
            public ?array $lastJson = null;

            public function postJson(string $path, array $json, float $timeoutSeconds = 0.2): array
            {
                $this->calls++;
                $this->lastPath = $path;
                $this->lastJson = $json;

                return [
                    'evaluations' => [
                        [
                            'key' => 'demo-kill-switch',
                            'variationKey' => 'on',
                            'valueJson' => 'true',
                            'version' => 3,
                            'reason' => 'default',
                        ],
                    ],
                ];
            }

            public function getJson(string $path, float $timeoutSeconds = 0.2): array
            {
                return [];
            }
        };

        $flags = new Flags(
            $config,
            new Identity(anonymousId: 'anon-1'),
            static fn () => $http,
        );

        self::assertTrue($flags->boolVariation('demo-kill-switch', false));
        self::assertTrue($flags->boolVariation('demo-kill-switch', false));
        self::assertSame(1, $http->calls);
        self::assertSame('v1/flags/evaluate', $http->lastPath);
        self::assertArrayNotHasKey('input', $http->lastJson ?? []);
        self::assertArrayNotHasKey('__className__', $http->lastJson ?? []);
        self::assertSame('anon-1', $http->lastJson['anonymousId'] ?? null);
        self::assertNotSame('', $http->lastJson['sessionId'] ?? '');
        self::assertSame(
            ['flag.demo-kill-switch' => 'on'],
            $flags->stampTags(),
        );
    }

    public function testLoadDefinitionsSwitchesToLocalMode(): void
    {
        $config = new Config([
            'url' => 'https://example.test',
            'apiKey' => \Talaria\Credential::example(),
        ]);
        $config->enableFlags = true;

        $http = new class implements FlagsHttpClient {
            public ?string $lastPath = null;

            public function postJson(string $path, array $json, float $timeoutSeconds = 0.2): array
            {
                return [];
            }

            public function getJson(string $path, float $timeoutSeconds = 0.2): array
            {
                $this->lastPath = $path;

                return [
                    'flags' => [
                        [
                            'key' => 'new-checkout',
                            'kind' => 'boolean',
                            'variations' => [
                                ['key' => 'on', 'valueJson' => 'true'],
                                ['key' => 'off', 'valueJson' => 'false'],
                            ],
                            'rules' => [
                                [
                                    'kind' => 'percentUsers',
                                    'variationKey' => 'on',
                                    'percent' => 60,
                                ],
                            ],
                            'defaultVariationKey' => 'off',
                            'offVariationKey' => 'off',
                            'enabled' => true,
                            'version' => 1,
                        ],
                    ],
                ];
            }
        };

        $flags = new Flags(
            $config,
            new Identity(userId: 'stable-user'),
            static fn () => $http,
        );
        $flags->loadDefinitions();
        self::assertSame('v1/flags', $http->lastPath);
        self::assertTrue($flags->isLocalMode());
        self::assertTrue($flags->boolVariation('new-checkout', false));
    }

    public function testApplySdkDocumentEnablesFlags(): void
    {
        $config = new Config([
            'url' => 'https://example.test',
            'apiKey' => \Talaria\Credential::example(),
        ]);
        self::assertFalse($config->enableFlags);
        $config->applySdkDocument([
            'schemaVersion' => 1,
            'active' => true,
            'flags' => ['enabled' => true],
            'events' => ['sampleRate' => 1],
            'tracing' => ['enabled' => false],
            'analytics' => ['enabled' => false],
        ]);
        self::assertTrue($config->enableFlags);
    }
}
