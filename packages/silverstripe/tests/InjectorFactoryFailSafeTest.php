<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Credential;
use Talaria\FailSafe;
use Talaria\SilverStripe\Config;
use Talaria\SilverStripe\InjectorFactory;
use Talaria\TalariaClient;

/**
 * An invalid or missing TALARIA_API_KEY must never break the site.
 */
final class InjectorFactoryFailSafeTest extends TestCase
{
    private string $log = '';

    private string|false $previousLog = false;

    protected function setUp(): void
    {
        InjectorFactory::reset();
        FailSafe::reset();
        $this->clearKeyEnv();
        $this->log = (string) tempnam(sys_get_temp_dir(), 'talaria-ss');
        $this->previousLog = ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        InjectorFactory::reset();
        FailSafe::reset();
        ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
        @unlink($this->log);
        if (class_exists(\SilverStripe\Core\Config\MemoryConfig::class)) {
            \SilverStripe\Core\Config\MemoryConfig::reset();
        }
        $this->clearKeyEnv();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badKeys(): iterable
    {
        yield 'old tal_live_ key' => ['tal_live_g2WhG4abcdefghijklmnopqrstuvwxyz0123456789'];
        yield 'wrong checksum' => [substr(Credential::example(), 0, -6) . 'AAAAAA'];
        yield 'empty key' => [''];
    }

    /**
     * @dataProvider badKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function testBadKeyGivesDisabledClientWithoutThrowing(string $key): void
    {
        Config::config()->set('apiKey', $key);

        $client = (new InjectorFactory())->create(TalariaClient::class);

        self::assertInstanceOf(TalariaClient::class, $client);
        self::assertFalse($client->isEnabled());
        $client->captureMessage('hello');
        $client->captureException(new \RuntimeException('boom'));
        $client->flush();
        self::assertSame(0, $client->queueSize());
        self::assertSame($client, (new InjectorFactory())->create(TalariaClient::class));

        $log = (string) file_get_contents($this->log);
        self::assertSame(1, substr_count($log, 'Talaria is disabled'));
        self::assertStringNotContainsString('g2WhG4', $log);
    }

    public function testBadBrowserKeyRendersNothing(): void
    {
        Config::config()->set('apiKey', 'tal_live_g2WhG4abcdefghijklmnopqrstuvwxyz0123456789');
        Config::config()->set('browser_api_key', 'tal_live_g2WhG4abcdefghijklmnopqrstuvwxyz0123456789');

        self::assertNull(Config::toBrowserOptions('silverstripe-frontend'));
    }

    public function testValidKeyStaysEnabled(): void
    {
        Config::config()->set('apiKey', Credential::example());
        Config::config()->set('url', 'https://ingest.example.test');

        $client = (new InjectorFactory())->create(TalariaClient::class);

        self::assertTrue($client->isEnabled());
        $client->close();
    }

    private function clearKeyEnv(): void
    {
        foreach (['TALARIA_API_KEY', 'TALARIA_BROWSER_API_KEY', 'TALARIA_URL'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
