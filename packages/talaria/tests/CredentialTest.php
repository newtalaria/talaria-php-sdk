<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Config;
use Talaria\Credential;

final class CredentialTest extends TestCase
{
    protected function tearDown(): void
    {
        Credential::resetPublishableWarning();
        putenv('TALARIA_URL');
        unset($_ENV['TALARIA_URL'], $_SERVER['TALARIA_URL']);
        parent::tearDown();
    }

    public function testChecksumRoundTripAndAlphabet(): void
    {
        $key = Credential::example('sk', 'prod', 'nz1');
        $parsed = Credential::parseApiKey($key);

        self::assertSame('sk', $parsed->class);
        self::assertSame('prod', $parsed->env);
        self::assertSame('nz1', $parsed->cell);
        self::assertTrue($parsed->isSecret());
        self::assertSame('https://ingest-nz1.newtalaria.com', $parsed->ingestUrl());
        self::assertMatchesRegularExpression(
            '/^tal_sk_prod_nz1_[A-Za-z0-9]{43}[0-9A-Za-z]{6}$/',
            $key,
        );
        self::assertSame('000000', Credential::checksum(''));
    }

    public function testRejectsBadChecksumClasslessKeyAndShortCell(): void
    {
        $key = Credential::example();
        $flipped = substr($key, 0, -1) . ($key[-1] === '0' ? '1' : '0');

        $this->expectException(\InvalidArgumentException::class);
        Credential::parseApiKey($flipped);
    }

    public function testRejectsClasslessKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Credential::parseApiKey('tal_prod_nz1_' . str_repeat('a', 49));
    }

    public function testMonitorToken(): void
    {
        $token = Credential::exampleMonitor('eu1-k7m2p9');
        $parsed = Credential::parseMonitorToken($token);

        self::assertSame('eu1-k7m2p9', $parsed->cell);
        self::assertNull($parsed->class);
        self::assertSame('https://ingest-eu1-k7m2p9.newtalaria.com', $parsed->ingestUrl());
    }

    public function testDerivesUrlFromKeyUnlessOverridden(): void
    {
        $derived = new Config(['apiKey' => Credential::example('sk', 'test', 'nz1')]);
        self::assertSame('https://ingest-nz1.newtalaria.com', $derived->url);

        $explicit = new Config([
            'url' => 'https://ingest.example.test',
            'apiKey' => Credential::example('sk', 'test', 'nz1'),
        ]);
        self::assertSame('https://ingest.example.test', $explicit->url);

        putenv('TALARIA_URL=https://override.example.test');
        $_ENV['TALARIA_URL'] = 'https://override.example.test';
        $fromEnv = new Config(['apiKey' => Credential::example('sk', 'test', 'eu1')]);
        self::assertSame('https://override.example.test', $fromEnv->url);
    }

    public function testSelfCellFailsFastWithoutUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('self');
        new Config(['apiKey' => Credential::example('sk', 'test', 'self')]);
    }

    public function testSelfCellAllowsExplicitUrl(): void
    {
        $config = new Config([
            'url' => 'https://talaria.internal',
            'apiKey' => Credential::example('sk', 'test', 'self'),
        ]);

        self::assertSame('https://talaria.internal', $config->url);
    }

    public function testPublishableKeyWarnsOnce(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'talaria-pk');
        self::assertIsString($log);
        $previous = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            new Config([
                'url' => 'https://ingest.example.test',
                'apiKey' => Credential::example('pk'),
            ]);
            new Config([
                'url' => 'https://ingest.example.test',
                'apiKey' => Credential::example('pk', 'prod', 'nz1'),
            ]);
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }

        self::assertSame(1, substr_count($written, 'publishable tal_pk_ key'));
    }
}
