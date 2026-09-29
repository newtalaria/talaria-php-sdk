<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
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

    public function testBrowserInjectUsesPhpKeyWhenBrowserKeyUnset(): void
    {
        $this->clearBrowserKeyEnv();
        $this->withConfig([
            'apiKey' => 'tal_live_php',
            'browserApiKey' => '',
            'dsn' => 'https://ingest.example.test',
        ], function (): void {
            $frontend = Config::toBrowserOptions('silverstripe-frontend');
            $cms = Config::toBrowserOptions('silverstripe-cms');

            self::assertIsArray($frontend);
            self::assertIsArray($cms);
            self::assertSame('tal_live_php', $frontend['apiKey']);
            self::assertSame('tal_live_php', $cms['apiKey']);
            self::assertSame('tal_live_php', Config::toClientOptions()['apiKey']);
        });
    }

    public function testBrowserInjectUsesSeparateKeyForCmsAndFrontend(): void
    {
        $this->clearBrowserKeyEnv();
        $this->withConfig([
            'apiKey' => 'tal_live_php',
            'browserApiKey' => 'tal_live_browser',
            'dsn' => 'https://ingest.example.test',
        ], function (): void {
            $frontend = Config::toBrowserOptions('silverstripe-frontend');
            $cms = Config::toBrowserOptions('silverstripe-cms');

            self::assertIsArray($frontend);
            self::assertIsArray($cms);
            self::assertSame('tal_live_browser', $frontend['apiKey']);
            self::assertSame('tal_live_browser', $cms['apiKey']);
            self::assertSame('tal_live_php', Config::toClientOptions()['apiKey']);
        });
    }

    public function testBrowserKeyFromEnvWhenYamlPlaceholderIsEmpty(): void
    {
        $this->setBrowserKeyEnv('tal_live_from_env');
        $this->withConfig([
            'apiKey' => 'tal_live_php',
            'browserApiKey' => '`TALARIA_BROWSER_API_KEY`',
            'dsn' => 'https://ingest.example.test',
        ], function (): void {
            $browser = Config::toBrowserOptions('silverstripe-frontend');

            self::assertIsArray($browser);
            self::assertSame('tal_live_from_env', $browser['apiKey']);
            self::assertSame('tal_live_php', Config::toClientOptions()['apiKey']);
        });
    }

    public function testInvalidBrowserKeyDisablesInjectAndLeavesPhpKey(): void
    {
        $this->clearBrowserKeyEnv();
        $this->withConfig([
            'apiKey' => 'tal_live_php',
            'browserApiKey' => 'not-a-project-key',
            'dsn' => 'https://ingest.example.test',
        ], function (): void {
            self::assertNull(Config::toBrowserOptions('silverstripe-frontend'));
            self::assertSame('tal_live_php', Config::toClientOptions()['apiKey']);
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
