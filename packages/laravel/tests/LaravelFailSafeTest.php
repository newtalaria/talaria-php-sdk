<?php

declare(strict_types=1);

namespace Talaria\Laravel\Tests;

use Talaria\FailSafe;
use Talaria\Laravel\Browser\BrowserScript;
use Talaria\Talaria;
use Talaria\TalariaClient;

/**
 * An invalid TALARIA_API_KEY must never break the app: the client is a no-op
 * and one warning goes to the Laravel log.
 */
final class LaravelFailSafeTest extends TestCase
{
    private const OLD_KEY = 'tal_live_g2WhG4abcdefghijklmnopqrstuvwxyz0123456789';

    private static string $logPath = '';

    protected function setUp(): void
    {
        FailSafe::reset();
        self::$logPath = sys_get_temp_dir() . '/talaria-laravel-' . bin2hex(random_bytes(4)) . '.log';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink(self::$logPath);
        FailSafe::reset();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('talaria.api_key', self::OLD_KEY);
        $app['config']->set('talaria.browser_api_key', self::OLD_KEY);
        $app['config']->set('logging.default', 'failsafe');
        $app['config']->set('logging.channels.failsafe', [
            'driver' => 'single',
            'path' => self::$logPath,
            'level' => 'debug',
        ]);
    }

    public function testMalformedKeyBootsWithDisabledClient(): void
    {
        $client = $this->app->make(TalariaClient::class);

        self::assertFalse($client->isEnabled());
        self::assertSame($client, Talaria::getClient());

        $client->captureMessage('hello');
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->report(new \RuntimeException('checkout failed'));
        $client->startTransaction('GET /')->end();
        $client->flush();

        self::assertSame([], $this->transport->allEvents());
        self::assertSame([], $this->spans->allSpans());
    }

    public function testRequestsStillSucceed(): void
    {
        $this->get('/ok')->assertOk();
        self::assertSame([], $this->transport->allEvents());
    }

    public function testOneWarningGoesToTheLaravelLog(): void
    {
        $this->app->make(TalariaClient::class);
        $log = is_file(self::$logPath) ? (string) file_get_contents(self::$logPath) : '';

        self::assertSame(1, substr_count($log, 'Talaria is disabled'), $log);
        self::assertStringContainsString('tal_live…', $log);
        self::assertStringNotContainsString('g2WhG4', $log);
    }

    public function testBrowserScriptRendersNothing(): void
    {
        self::assertNull($this->app->make(BrowserScript::class)->html());
    }

    public function testEmptyKeyAlsoDisables(): void
    {
        $this->app->forgetInstance(TalariaClient::class);
        $this->app['config']->set('talaria.api_key', '');

        $client = $this->app->make(TalariaClient::class);

        self::assertFalse($client->isEnabled());
    }
}
