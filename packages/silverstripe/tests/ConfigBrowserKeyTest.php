<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Credential;
use Talaria\SilverStripe\Config;

final class ConfigBrowserKeyTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearBrowserKeyEnv();
        if (class_exists(\SilverStripe\Core\Config\MemoryConfig::class)) {
            \SilverStripe\Core\Config\MemoryConfig::reset();
        }
        parent::tearDown();
    }

    public function testBrowserInjectRequiresPublishableKey(): void
    {
        $this->clearBrowserKeyEnv();
        $this->withConfig([
            'apiKey' => Credential::example(),
            'browser_api_key' => '',
            'url' => 'https://ingest.example.test',
        ], function (): void {
            self::assertNull(Config::toBrowserOptions('silverstripe-frontend'));
            self::assertSame(Credential::example(), Config::toClientOptions()['apiKey']);
        });
    }

    public function testBrowserInjectUsesPublishableKey(): void
    {
        $this->clearBrowserKeyEnv();
        $pk = Credential::example('pk');
        $this->withConfig([
            'apiKey' => Credential::example(),
            'browser_api_key' => $pk,
            'url' => 'https://ingest.example.test',
        ], function () use ($pk): void {
            $frontend = Config::toBrowserOptions('silverstripe-frontend');
            $cms = Config::toBrowserOptions('silverstripe-cms');

            self::assertIsArray($frontend);
            self::assertIsArray($cms);
            self::assertSame($pk, $frontend['apiKey']);
            self::assertSame($pk, $cms['apiKey']);
            self::assertSame('https://ingest.example.test', $frontend['url']);
            self::assertSame(Credential::example(), Config::toClientOptions()['apiKey']);
        });
    }

    public function testBrowserKeyFromEnvWhenYamlPlaceholderIsEmpty(): void
    {
        $pk = Credential::example('pk', 'prod', 'nz1');
        $this->setBrowserKeyEnv($pk);
        $this->withConfig([
            'apiKey' => Credential::example(),
            'browser_api_key' => '`TALARIA_BROWSER_API_KEY`',
            'url' => 'https://ingest.example.test',
        ], function () use ($pk): void {
            $browser = Config::toBrowserOptions('silverstripe-frontend');

            self::assertIsArray($browser);
            self::assertSame($pk, $browser['apiKey']);
            self::assertSame(Credential::example(), Config::toClientOptions()['apiKey']);
        });
    }

    public function testSecretBrowserKeyDisablesInject(): void
    {
        $this->clearBrowserKeyEnv();
        $this->withConfig([
            'apiKey' => Credential::example(),
            'browser_api_key' => Credential::example(),
            'url' => 'https://ingest.example.test',
        ], function (): void {
            self::assertNull(Config::toBrowserOptions('silverstripe-frontend'));
            self::assertSame(Credential::example(), Config::toClientOptions()['apiKey']);
        });
    }

    public function testBrowserUrlDerivesFromPublishableKey(): void
    {
        $this->clearBrowserKeyEnv();
        $pk = Credential::example('pk', 'test', 'eu1');
        $this->withConfig([
            'apiKey' => Credential::example(),
            'browser_api_key' => $pk,
            'url' => '',
        ], function () use ($pk): void {
            $browser = Config::toBrowserOptions('silverstripe-frontend');

            self::assertIsArray($browser);
            self::assertSame($pk, $browser['apiKey']);
            self::assertSame('https://ingest-eu1.newtalaria.com', $browser['url']);
        });
    }

    /**
     * @param array<string, mixed> $values
     */
    private function withConfig(array $values, callable $fn): void
    {
        $cfg = Config::config();
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = $cfg->get($key);
            $cfg->set($key, $value);
        }

        try {
            $fn();
        } finally {
            foreach ($previous as $key => $value) {
                $cfg->set($key, $value);
            }
        }
    }

    private function setBrowserKeyEnv(string $value): void
    {
        putenv('TALARIA_BROWSER_API_KEY=' . $value);
        $_ENV['TALARIA_BROWSER_API_KEY'] = $value;
        $_SERVER['TALARIA_BROWSER_API_KEY'] = $value;
        if (class_exists(\SilverStripe\Core\Environment::class)) {
            \SilverStripe\Core\Environment::setEnv('TALARIA_BROWSER_API_KEY', $value);
        }
    }

    private function clearBrowserKeyEnv(): void
    {
        putenv('TALARIA_BROWSER_API_KEY');
        unset($_ENV['TALARIA_BROWSER_API_KEY'], $_SERVER['TALARIA_BROWSER_API_KEY']);
        if (class_exists(\SilverStripe\Core\Environment::class)) {
            \SilverStripe\Core\Environment::setEnv('TALARIA_BROWSER_API_KEY', null);
        }
    }
}
