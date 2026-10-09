<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SilverStripe\SiteHost\CheckInTransport;
use Talaria\SilverStripe\SiteHost\SiteHostInstaller;
use Talaria\SilverStripe\SiteHost\SiteHostInstallException;

final class SiteHostInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/talaria-sitehost-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/talaria/sitehost', 0777, true);
        mkdir($this->root . '/cron', 0777, true);
        mkdir($this->root . '/supervisor', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testInvalidJsonFails(): void
    {
        $this->expectException(SiteHostInstallException::class);
        $this->expectExceptionMessage('not valid JSON');
        SiteHostInstaller::parseSpec('{');
    }

    public function testMissingCommandFails(): void
    {
        $this->expectException(SiteHostInstallException::class);
        $this->expectExceptionMessage('needs command');
        SiteHostInstaller::parseSpec(json_encode([
            'jobs' => [[
                'slug' => 'process-job-queue',
                'crontab' => '* * * * *',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testReconcileCommentsLegacyAndIsIdempotent(): void
    {
        $jobs = $this->jobs();
        $legacy = <<<'CRON'
MAILTO=""
* * * * * cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask
0 1 * * * cd /container/application && vendor/bin/sake dev/tasks/SyncCareersDataTask w=1 >> /container/logs/cron-sync-careers.log 2>&1
0 0 * * * cd /container/application && vendor/bin/sake dev/tasks/SomethingElse
CRON;
        $tokens = [
            'process-job-queue' => 'tal_ping_aaa',
            'sync-careers' => 'tal_ping_bbb',
        ];
        $once = SiteHostInstaller::reconcileCrontab($legacy, $jobs, 'https://api.newtalaria.com', $tokens);
        $twice = SiteHostInstaller::reconcileCrontab($once, $jobs, 'https://api.newtalaria.com/', $tokens);

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, SiteHostInstaller::CRON_BEGIN));
        self::assertSame(1, substr_count($twice, SiteHostInstaller::CRON_END));
        self::assertSame(1, substr_count($twice, '# talaria-monitor:process-job-queue'));
        self::assertStringContainsString('# talaria-sitehost retired * * * * * cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask', $twice);
        self::assertStringContainsString('SomethingElse', $twice);
        self::assertDoesNotMatchRegularExpression('/^0 1 \* \* \* cd /m', $twice);
        self::assertStringContainsString("https://api.newtalaria.com/monitors/ping", $twice);
    }

    public function testExistingMonitorLinesFoldIntoOneBlock(): void
    {
        $jobs = [$this->jobs()[0]];
        $existing = "* * * * * curl https://api.example/monitors/ping; cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask; exit 0 # talaria-monitor:process-job-queue\n";
        $once = SiteHostInstaller::reconcileCrontab($existing, $jobs, 'https://api.newtalaria.com', [
            'process-job-queue' => 'tal_ping_aaa',
        ]);

        self::assertSame(1, substr_count($once, 'ProcessJobQueueTask'));
        self::assertStringContainsString(SiteHostInstaller::CRON_BEGIN, $once);
    }

    public function testSupervisorBlockIsReplacedInPlace(): void
    {
        $existing = "[program:cron]\ncommand=cron -f\n\n"
            . SiteHostInstaller::PROBE_BEGIN . "\ncommand=old\n" . SiteHostInstaller::PROBE_END . "\n"
            . SiteHostInstaller::PROBE_BEGIN . "\ncommand=older\n" . SiteHostInstaller::PROBE_END . "\n";
        $once = SiteHostInstaller::reconcileSupervisor($existing, '/container/application');
        $twice = SiteHostInstaller::reconcileSupervisor($once, '/container/application/');

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, '[program:talaria-sitehost-probe]'));
        self::assertStringContainsString('command=/container/application/vendor/bin/talaria-sitehost-probe', $twice);
        self::assertStringContainsString('[program:cron]', $twice);
        self::assertStringNotContainsString('command=old', $twice);
    }

    public function testBrokenProbeBlockFails(): void
    {
        $this->expectException(SiteHostInstallException::class);
        $this->expectExceptionMessage('end-talaria-sitehost-probe');
        SiteHostInstaller::reconcileSupervisor(SiteHostInstaller::PROBE_BEGIN . "\ncommand=old\n", '/container/application');
    }

    public function testFailedRegistrationDoesNotWriteCrontab(): void
    {
        $this->writeSpec();
        $cron = $this->root . '/cron/crontab';
        file_put_contents($cron, "MAILTO=\"\"\n* * * * * cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask\n");
        $supervisor = $this->root . '/supervisor/supervisord.conf';
        file_put_contents($supervisor, "[program:cron]\ncommand=cron -f\n");
        $transport = self::transport(static fn (): array => [
            'code' => 400,
            'body' => json_encode(['data' => ['message' => 'API key lacks required scope: monitors:write']], JSON_THROW_ON_ERROR),
        ]);

        try {
            $this->installer($transport)->install();
            self::fail('expected the registration to fail');
        } catch (SiteHostInstallException $exception) {
            self::assertStringContainsString('HTTP 400', $exception->getMessage());
            self::assertStringContainsString('monitors:write', $exception->getMessage());
        }

        $written = (string) file_get_contents($cron);
        self::assertStringNotContainsString('talaria-sitehost begin', $written);
        self::assertStringNotContainsString('[program:talaria-sitehost-probe]', (string) file_get_contents($supervisor));
    }

    public function testSecondRunReusesPingToken(): void
    {
        $this->writeSpec();
        $supervisor = $this->root . '/supervisor/supervisord.conf';
        file_put_contents($supervisor, "[program:cron]\ncommand=cron -f\n");
        $calls = 0;
        $transport = self::transport(static function () use (&$calls): array {
            $calls++;
            $token = $calls === 1 ? 'tal_ping_aaa' : 'tal_ping_bbb';

            return [
                'code' => 200,
                'body' => json_encode(['pingToken' => $token], JSON_THROW_ON_ERROR),
            ];
        });
        $installer = $this->installer($transport);
        $installer->install();
        $installer->install();

        self::assertSame(2, $calls);
        $cron = (string) file_get_contents($this->root . '/cron/crontab');
        self::assertSame(1, substr_count($cron, SiteHostInstaller::CRON_BEGIN));
        self::assertStringContainsString('tal_ping_aaa', $cron);
        self::assertStringContainsString('tal_ping_bbb', $cron);
        self::assertSame(1, substr_count((string) file_get_contents($supervisor), '[program:talaria-sitehost-probe]'));
        $tokens = (string) file_get_contents($this->root . '/app/.talaria-monitor-tokens');
        self::assertStringContainsString('process-job-queue=tal_ping_aaa', $tokens);
        self::assertStringContainsString('sync-careers=tal_ping_bbb', $tokens);
    }

    public function testPartialRegistrationCanBeRerun(): void
    {
        $this->writeSpec();
        file_put_contents($this->root . '/supervisor/supervisord.conf', "[program:cron]\ncommand=cron -f\n");
        $attempts = 0;
        $transport = self::transport(static function () use (&$attempts): array {
            $attempts++;
            if ($attempts === 1) {
                return ['code' => 200, 'body' => json_encode(['pingToken' => 'tal_ping_aaa'], JSON_THROW_ON_ERROR)];
            }
            if ($attempts === 2) {
                return ['code' => 400, 'body' => json_encode(['message' => 'unavailable'], JSON_THROW_ON_ERROR)];
            }

            return ['code' => 200, 'body' => json_encode(['pingToken' => 'tal_ping_bbb'], JSON_THROW_ON_ERROR)];
        });
        $installer = $this->installer($transport);
        try {
            $installer->install();
            self::fail('expected the second check-in to fail');
        } catch (SiteHostInstallException $exception) {
            self::assertStringContainsString('sync-careers', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/cron/crontab');
        self::assertStringContainsString('process-job-queue=tal_ping_aaa', (string) file_get_contents($this->root . '/app/.talaria-monitor-tokens'));

        $installer->install();
        $cron = (string) file_get_contents($this->root . '/cron/crontab');
        self::assertStringContainsString('tal_ping_aaa', $cron);
        self::assertStringContainsString('tal_ping_bbb', $cron);
        self::assertSame(3, $attempts);
    }

    /**
     * @return list<array{slug: string, crontab: string, timezone: string, command: string, marginSeconds: ?int, maxRuntimeSeconds: ?int}>
     */
    private function jobs(): array
    {
        return SiteHostInstaller::parseSpec((string) json_encode([
            'jobs' => [
                [
                    'slug' => 'process-job-queue',
                    'crontab' => '* * * * *',
                    'timezone' => 'Pacific/Auckland',
                    'maxRuntimeSeconds' => 55,
                    'marginSeconds' => 120,
                    'command' => 'cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask',
                ],
                [
                    'slug' => 'sync-careers',
                    'crontab' => '0 1 * * *',
                    'timezone' => 'Pacific/Auckland',
                    'command' => 'cd /container/application && vendor/bin/sake dev/tasks/SyncCareersDataTask w=1',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeSpec(): void
    {
        file_put_contents(
            $this->root . '/app/talaria/sitehost/monitors.json',
            json_encode(['jobs' => [
                [
                    'slug' => 'process-job-queue',
                    'crontab' => '* * * * *',
                    'timezone' => 'Pacific/Auckland',
                    'command' => 'cd /container/application && vendor/bin/sake dev/tasks/ProcessJobQueueTask',
                ],
                [
                    'slug' => 'sync-careers',
                    'crontab' => '0 1 * * *',
                    'timezone' => 'Pacific/Auckland',
                    'command' => 'cd /container/application && vendor/bin/sake dev/tasks/SyncCareersDataTask w=1',
                ],
            ]], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param callable(): array{code: int, body: string} $handler
     */
    private static function transport(callable $handler): CheckInTransport
    {
        return new class ($handler) implements CheckInTransport {
            /**
             * @param callable(): array{code: int, body: string} $handler
             */
            public function __construct(private $handler)
            {
            }

            public function post(string $url, string $apiKey, string $json): array
            {
                return ($this->handler)();
            }
        };
    }

    private function installer(CheckInTransport $transport): SiteHostInstaller
    {
        return new SiteHostInstaller(
            $this->root . '/app',
            $this->root . '/app/talaria/sitehost/monitors.json',
            $this->root . '/cron/crontab',
            $this->root . '/supervisor/supervisord.conf',
            $this->root . '/app/.talaria-monitor-tokens',
            'https://api.newtalaria.com',
            'tal_live_test',
            $transport,
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
