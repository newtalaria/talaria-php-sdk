<?php

declare(strict_types=1);

namespace Talaria\SilverStripe\SiteHost;

use Talaria\Monitor\MonitorCheckIn;

/**
 * Reconciles SiteHost crontab check-ins, the supervisord probe, and the
 * log collector. Cron still pings with curl.
 */
final class SiteHostInstaller
{
    public const CRON_BEGIN = '# talaria-sitehost begin';

    public const CRON_END = '# talaria-sitehost end';

    public const PROBE_BEGIN = '# talaria-sitehost-probe';

    public const PROBE_END = '# end-talaria-sitehost-probe';

    public const COLLECTOR_BEGIN = '# talaria-otelcol';

    public const COLLECTOR_END = '# end-talaria-otelcol';

    public const COLLECTOR_VERSION = '0.162.0';

    public const COLLECTOR_SHA256 = 'fcc063749f730f8c21fe29f2d340ff174f5f1c5885bd3156fb6c985a3036fcc3';

    public function __construct(
        private readonly string $appPath,
        private readonly string $specPath,
        private readonly string $cronPath,
        private readonly string $supervisorPath,
        private readonly string $tokenPath,
        private readonly string $dsn,
        private readonly string $apiKey,
        private readonly CheckInTransport $transport,
        private readonly bool $probeOnly = false,
        private readonly bool $installCollector = false,
        private readonly string $collectorConfigPath = '/container/config/talaria-otelcol.yaml',
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv): int
    {
        if (($argv[1] ?? '') !== 'install') {
            fwrite(STDERR, "Usage: talaria-sitehost install\n");

            return 1;
        }
        try {
            self::fromEnvironment()->install();
        } catch (SiteHostInstallException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    public static function fromEnvironment(?CheckInTransport $transport = null): self
    {
        $app = rtrim((string) getenv('SITEHOST_APP_PATH'), '/');
        if ($app === '') {
            throw new SiteHostInstallException('SITEHOST_APP_PATH is required');
        }

        return new self(
            $app,
            $app . '/talaria/sitehost/monitors.json',
            (string) (getenv('SITEHOST_CRONTAB') ?: '/container/crontabs/crontab'),
            (string) (getenv('SITEHOST_SUPERVISORD') ?: '/container/config/supervisord.conf'),
            $app . '/.talaria-monitor-tokens',
            trim((string) (getenv('TALARIA_DSN') ?: getenv('TALARIA_BASE_URL') ?: '')),
            trim((string) (getenv('TALARIA_API_KEY') ?: '')),
            $transport ?? new PhpStreamCheckInTransport(),
            getenv('TALARIA_INSTALL_PROBE') === 'true',
            true,
        );
    }

    public function install(): void
    {
        $hasSpec = is_file($this->specPath);
        if (!$hasSpec && !$this->probeOnly) {
            return;
        }
        if (!is_file($this->supervisorPath)) {
            throw new SiteHostInstallException(
                'supervisord config is missing at ' . $this->supervisorPath,
            );
        }

        $jobs = [];
        if ($hasSpec) {
            $raw = file_get_contents($this->specPath);
            if (!is_string($raw)) {
                throw new SiteHostInstallException('Could not read ' . $this->specPath);
            }
            $jobs = self::parseSpec($raw);
        }

        if ($jobs !== []) {
            $base = self::baseUrl($this->dsn);
            self::assertApiKey($this->apiKey);
            $tokens = $this->readTokens();
            foreach ($jobs as $job) {
                $slug = $job['slug'];
                if (($tokens[$slug] ?? '') !== '') {
                    continue;
                }
                $tokens[$slug] = $this->register($job, $base);
                $this->writeTokens($this->tokensForJobs($jobs, $tokens));
            }
            $existing = is_file($this->cronPath) ? (string) file_get_contents($this->cronPath) : '';
            $cron = self::reconcileCrontab($existing, $jobs, $base, $tokens);
            $this->atomicWrite($this->cronPath, $cron, 0644);
        }

        $supervisor = self::reconcileSupervisor(
            (string) file_get_contents($this->supervisorPath),
            $this->appPath,
        );
        if ($this->installCollector) {
            $this->ensureCollectorBinary();
            $config = self::collectorConfig(
                self::baseUrl($this->dsn) . '/otlp',
                self::collectorResource('TALARIA_SERVICE_NAME', 'silverstripe'),
                self::collectorResource('TALARIA_CONTAINER_NAME', (string) (gethostname() ?: 'container')),
                self::collectorResource('TALARIA_ENVIRONMENT', 'production'),
            );
            $this->atomicWrite($this->collectorConfigPath, $config, 0644);
            $supervisor = self::reconcileCollector(
                $supervisor,
                $this->appPath,
                $this->collectorConfigPath,
            );
        }
        $this->atomicWrite($this->supervisorPath, $supervisor, 0644);
        if ($this->installCollector) {
            $this->reloadSupervisor();
        }
    }

    /**
     * @return list<array{slug: string, crontab: string, timezone: string, command: string, marginSeconds: ?int, maxRuntimeSeconds: ?int}>
     */
    public static function parseSpec(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SiteHostInstallException('monitors.json is not valid JSON: ' . $exception->getMessage());
        }
        if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
            throw new SiteHostInstallException('monitors.json needs a jobs array');
        }
        if ($decoded['jobs'] === []) {
            throw new SiteHostInstallException('monitors.json jobs array is empty');
        }

        $jobs = [];
        $seen = [];
        foreach ($decoded['jobs'] as $index => $job) {
            if (!is_array($job)) {
                throw new SiteHostInstallException('monitors.json job ' . $index . ' must be an object');
            }
            $slug = self::requireString($job, 'slug', $index);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,80}$/', $slug) !== 1) {
                throw new SiteHostInstallException('monitors.json slug is invalid: ' . $slug);
            }
            if (isset($seen[$slug])) {
                throw new SiteHostInstallException('monitors.json repeats slug ' . $slug);
            }
            $seen[$slug] = true;
            $crontab = self::requireString($job, 'crontab', $index);
            self::assertCrontab($crontab, $slug);
            $command = self::requireString($job, 'command', $index);
            self::assertCommand($command, $slug);
            $timezone = isset($job['timezone']) ? self::requireString($job, 'timezone', $index) : 'UTC';
            if (preg_match('/^[A-Za-z0-9_+\-\/]{1,64}$/', $timezone) !== 1) {
                throw new SiteHostInstallException('monitors.json timezone is invalid for ' . $slug);
            }
            $jobs[] = [
                'slug' => $slug,
                'crontab' => $crontab,
                'timezone' => $timezone,
                'command' => $command,
                'marginSeconds' => self::optionalInt($job, 'marginSeconds', $slug),
                'maxRuntimeSeconds' => self::optionalInt($job, 'maxRuntimeSeconds', $slug),
            ];
        }

        return $jobs;
    }

    /**
     * @param list<array{slug: string, crontab: string, command: string}> $jobs
     * @param array<string, string> $tokens
     */
    public static function reconcileCrontab(string $existing, array $jobs, string $baseUrl, array $tokens): string
    {
        $baseUrl = self::baseUrl($baseUrl);
        $lines = self::splitLines($existing);
        $lines = self::stripManagedCron($lines);
        $lines = self::retireLegacy($lines, $jobs);
        $block = [self::CRON_BEGIN];
        foreach ($jobs as $job) {
            $token = $tokens[$job['slug']] ?? '';
            if (preg_match('/^tal_ping_[A-Za-z0-9]+$/', $token) !== 1) {
                throw new SiteHostInstallException('No ping token for ' . $job['slug']);
            }
            $block[] = self::crontabLine($job['crontab'], $job['command'], $token, $baseUrl, $job['slug']);
        }
        $block[] = self::CRON_END;
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        $all = $lines === [] ? $block : array_merge($lines, [''], $block);

        return implode("\n", $all) . "\n";
    }

    public static function reconcileSupervisor(string $existing, string $appPath): string
    {
        $appPath = rtrim($appPath, '/');
        if ($appPath === '' || str_contains($appPath, "\n") || str_contains($appPath, "'")) {
            throw new SiteHostInstallException('Application path cannot be written into supervisord');
        }
        $lines = self::splitLines($existing);
        $kept = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) !== self::PROBE_BEGIN) {
                $kept[] = $lines[$i];
                continue;
            }
            $end = null;
            for ($j = $i + 1; $j < $count && $j - $i <= 40; $j++) {
                if (trim($lines[$j]) === self::PROBE_END) {
                    $end = $j;
                    break;
                }
            }
            if ($end === null) {
                throw new SiteHostInstallException(
                    'supervisord probe block is missing # end-talaria-sitehost-probe',
                );
            }
            $i = $end;
        }
        while ($kept !== [] && trim((string) end($kept)) === '') {
            array_pop($kept);
        }
        $block = [
            self::PROBE_BEGIN,
            '[program:talaria-sitehost-probe]',
            'command=' . $appPath . '/vendor/bin/talaria-sitehost-probe',
            'directory=' . $appPath,
            'autostart=true',
            'autorestart=true',
            'stdout_logfile=/container/logs/talaria-sitehost-probe.log',
            'stderr_logfile=/container/logs/talaria-sitehost-probe.err',
            self::PROBE_END,
        ];
        $all = $kept === [] ? $block : array_merge($kept, [''], $block);

        return implode("\n", $all) . "\n";
    }

    public static function reconcileCollector(string $existing, string $appPath, string $configPath): string
    {
        $appPath = self::supervisordPath($appPath, 'Application path');
        $configPath = self::supervisordPath($configPath, 'Collector config path');
        $binary = $appPath . '/.talaria/otelcol-contrib';
        $lines = self::splitLines($existing);
        $kept = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) !== self::COLLECTOR_BEGIN) {
                $kept[] = $lines[$i];
                continue;
            }
            $end = null;
            for ($j = $i + 1; $j < $count && $j - $i <= 40; $j++) {
                if (trim($lines[$j]) === self::COLLECTOR_END) {
                    $end = $j;
                    break;
                }
            }
            if ($end === null) {
                throw new SiteHostInstallException(
                    'supervisord collector block is missing # end-talaria-otelcol',
                );
            }
            $i = $end;
        }
        while ($kept !== [] && trim((string) end($kept)) === '') {
            array_pop($kept);
        }
        $block = [
            self::COLLECTOR_BEGIN,
            '[program:talaria-otelcol]',
            'command=' . $binary . ' --config=' . $configPath,
            'directory=' . $appPath,
            'autostart=true',
            'autorestart=true',
            'stdout_logfile=/container/logs/talaria-otelcol.log',
            'stderr_logfile=/container/logs/talaria-otelcol.err',
            self::COLLECTOR_END,
        ];
        $all = $kept === [] ? $block : array_merge($kept, [''], $block);

        return implode("\n", $all) . "\n";
    }

    public static function collectorConfig(
        string $endpoint,
        string $service,
        string $container,
        string $environment,
    ): string {
        if (preg_match('#^https?://[^\'\s]+/otlp$#', $endpoint) !== 1) {
            throw new SiteHostInstallException('Collector endpoint must end in /otlp');
        }
        $service = self::collectorAttribute($service, 'service.name');
        $container = self::collectorAttribute($container, 'container.name');
        $environment = self::collectorAttribute($environment, 'deployment.environment.name');
        $template = <<<'YAML'
receivers:
  filelog:
    include:
      - /container/logs/apache2/error.log
      - /container/logs/php-fpm/*.log
      - /container/logs/cron-*.log
      - /container/logs/sitehost/sitehost.log
    exclude:
      - /container/logs/**/*.gz
      - /container/logs/apache2/access.log
      - /container/logs/apache2/other_vhosts_access.log
      - /container/logs/rsyslog/*
      - /container/logs/supervisor/*
      - /container/logs/talaria-otelcol.log
      - /container/logs/talaria-otelcol.err
      - /container/logs/talaria-sitehost-probe.log
      - /container/logs/talaria-sitehost-probe.err
    start_at: end
    operators:
      - type: regex_parser
        parse_from: body
        regex: '\[(?:[^\]]*:)?(?P<level>notice|info|warn|warning|error|crit|alert|emerg|critical|debug)\]'
        on_error: send_quiet
      - type: regex_parser
        parse_from: body
        regex: '(?i)(?:^|\s)(?P<level>NOTICE|INFO|WARNING|WARN|ERROR|CRITICAL|CRIT|ALERT|EMERG|DEBUG)\s*:'
        on_error: send_quiet
      - type: severity_parser
        parse_from: attributes.level
        on_error: send_quiet
        mapping:
          info:
            - notice
            - info
          warn:
            - warn
            - warning
          error:
            - error
          fatal:
            - crit
            - alert
            - emerg
            - critical
processors:
  memory_limiter:
    check_interval: 1s
    limit_mib: 128
    spike_limit_mib: 25
  resource:
    attributes:
      - key: service.name
        value: "___SERVICE___"
        action: upsert
      - key: container.name
        value: "___CONTAINER___"
        action: upsert
      - key: deployment.environment.name
        value: "___ENVIRONMENT___"
        action: upsert
  batch: {}
exporters:
  otlphttp:
    endpoint: "___ENDPOINT___"
    headers:
      X-API-Key: ${env:TALARIA_API_KEY}
    compression: gzip
service:
  pipelines:
    logs:
      receivers: [filelog]
      processors: [memory_limiter, resource, batch]
      exporters: [otlphttp]
YAML;

        return strtr($template, [
            '___ENDPOINT___' => $endpoint,
            '___SERVICE___' => $service,
            '___CONTAINER___' => $container,
            '___ENVIRONMENT___' => $environment,
        ]) . "\n";
    }

    /**
     * @param array{slug: string, crontab: string, timezone: string, command: string, marginSeconds: ?int, maxRuntimeSeconds: ?int} $job
     */
    private function register(array $job, string $base): string
    {
        $schedule = [
            'crontab' => $job['crontab'],
            'timezone' => $job['timezone'],
        ];
        if ($job['marginSeconds'] !== null) {
            $schedule['marginSeconds'] = $job['marginSeconds'];
        }
        if ($job['maxRuntimeSeconds'] !== null) {
            $schedule['maxRuntimeSeconds'] = $job['maxRuntimeSeconds'];
        }
        $json = json_encode(MonitorCheckIn::payload($job['slug'], 'ok', $schedule));
        if (!is_string($json)) {
            throw new SiteHostInstallException('Could not encode the check-in for ' . $job['slug']);
        }
        $result = $this->transport->post($base . '/monitors/checkIn', $this->apiKey, $json);
        $token = self::pingTokenFrom($result['body']);
        if (preg_match('/^tal_ping_[A-Za-z0-9]+$/', $token) !== 1) {
            throw new SiteHostInstallException(
                'Check-in for ' . $job['slug'] . ' failed: HTTP ' . $result['code'] . ' ' . self::errorDetail($result['body']),
            );
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function requireString(array $job, string $field, int $index): string
    {
        $value = $job[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $slug = is_string($job['slug'] ?? null) ? $job['slug'] : (string) $index;
            throw new SiteHostInstallException('monitors.json job ' . $slug . ' needs ' . $field);
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function optionalInt(array $job, string $field, string $slug): ?int
    {
        if (!array_key_exists($field, $job) || $job[$field] === null || $job[$field] === '') {
            return null;
        }
        if (is_int($job[$field])) {
            return $job[$field];
        }
        if (is_string($job[$field]) && preg_match('/^\d+$/', $job[$field]) === 1) {
            return (int) $job[$field];
        }
        throw new SiteHostInstallException('monitors.json ' . $field . ' must be an integer for ' . $slug);
    }

    private static function assertCrontab(string $crontab, string $slug): void
    {
        if (str_contains($crontab, "\n") || str_contains($crontab, '#')) {
            throw new SiteHostInstallException('monitors.json crontab is invalid for ' . $slug);
        }
        $fields = preg_split('/\s+/', $crontab) ?: [];
        if (count($fields) !== 5) {
            throw new SiteHostInstallException('monitors.json crontab needs 5 fields for ' . $slug);
        }
    }

    private static function assertCommand(string $command, string $slug): void
    {
        if (str_contains($command, "\n") || str_contains($command, "\r") || str_contains($command, '#')) {
            throw new SiteHostInstallException('monitors.json command is invalid for ' . $slug);
        }
    }

    private static function assertApiKey(string $apiKey): void
    {
        if ($apiKey === '') {
            throw new SiteHostInstallException('Set TALARIA_DSN and TALARIA_API_KEY to register check-ins');
        }
    }

    private static function baseUrl(string $dsn): string
    {
        $base = rtrim(trim($dsn), '/');
        if (preg_match('#^https?://[^\'\s]+$#', $base) !== 1) {
            throw new SiteHostInstallException('Set TALARIA_DSN and TALARIA_API_KEY to register check-ins');
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $existing): array
    {
        if ($existing === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $existing);
        if ($lines === false) {
            return [];
        }
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function stripManagedCron(array $lines): array
    {
        $kept = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) === self::CRON_BEGIN) {
                $end = null;
                for ($j = $i + 1; $j < $count && $j - $i <= 200; $j++) {
                    if (trim($lines[$j]) === self::CRON_END) {
                        $end = $j;
                        break;
                    }
                }
                if ($end === null) {
                    throw new SiteHostInstallException('crontab block is missing # talaria-sitehost end');
                }
                $i = $end;
                continue;
            }
            if (str_contains($lines[$i], '# talaria-monitor:')) {
                continue;
            }
            $kept[] = $lines[$i];
        }

        return $kept;
    }

    /**
     * @param list<string> $lines
     * @param list<array{command: string}> $jobs
     * @return list<string>
     */
    private static function retireLegacy(array $lines, array $jobs): array
    {
        $commands = [];
        foreach ($jobs as $job) {
            $commands[] = $job['command'];
        }
        usort($commands, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $retired = [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            $active = $trim !== '' && !str_starts_with($trim, '#');
            $matches = false;
            if ($active) {
                foreach ($commands as $command) {
                    if (str_contains($line, $command)) {
                        $matches = true;
                        break;
                    }
                }
            }
            $retired[] = $matches ? '# talaria-sitehost retired ' . $line : $line;
        }

        return $retired;
    }

    private static function crontabLine(
        string $schedule,
        string $command,
        string $token,
        string $url,
        string $slug,
    ): string {
        $ping = $url . '/monitors/ping';
        $curl = "curl -fsS -m 15 -X POST -H 'X-Monitor-Token: {$token}' -H 'Content-Type: application/json'";

        return $schedule . ' ' . $curl . " -d '{\"status\":\"in_progress\"}' '{$ping}'; "
            . $command . '; talaria_status=$?; if [ "$talaria_status" -eq 0 ]; then '
            . $curl . " -d '{\"status\":\"ok\"}' '{$ping}'; else "
            . $curl . " -d '{\"status\":\"error\"}' '{$ping}'; fi; exit \"\$talaria_status\" # talaria-monitor:{$slug}";
    }

    private static function pingTokenFrom(string $body): string
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        if (!is_array($data)) {
            return '';
        }
        if (is_string($data['pingToken'] ?? null) && $data['pingToken'] !== '') {
            return $data['pingToken'];
        }
        $result = $data['result'] ?? null;
        if (is_array($result) && is_string($result['pingToken'] ?? null)) {
            return $result['pingToken'];
        }

        return '';
    }

    private static function errorDetail(string $body): string
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return substr(trim($body), 0, 300);
        }
        if (!is_array($data)) {
            return substr(trim($body), 0, 300);
        }
        $inner = $data['data'] ?? null;
        $message = '';
        if (is_array($inner) && is_string($inner['message'] ?? null)) {
            $message = $inner['message'];
        }
        if ($message === '' && is_string($data['message'] ?? null)) {
            $message = $data['message'];
        }
        if ($message === '') {
            $message = trim($body);
        }

        return substr($message, 0, 300);
    }

    /**
     * @return array<string, string>
     */
    private function readTokens(): array
    {
        if (!is_file($this->tokenPath)) {
            return [];
        }
        $raw = file_get_contents($this->tokenPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $tokens = [];
        foreach (self::splitLines($raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9][A-Za-z0-9_-]{0,80})=(tal_ping_[A-Za-z0-9]+)$/', trim($line), $match) !== 1) {
                throw new SiteHostInstallException('The ping token file has a line that is not slug=token');
            }
            $tokens[$match[1]] = $match[2];
        }

        return $tokens;
    }

    /**
     * @param list<array{slug: string}> $jobs
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    private function tokensForJobs(array $jobs, array $tokens): array
    {
        $kept = [];
        foreach ($jobs as $job) {
            $slug = $job['slug'];
            if (($tokens[$slug] ?? '') !== '') {
                $kept[$slug] = $tokens[$slug];
            }
        }

        return $kept;
    }

    /**
     * @param array<string, string> $tokens
     */
    private function writeTokens(array $tokens): void
    {
        $lines = [];
        foreach ($tokens as $slug => $token) {
            $lines[] = $slug . '=' . $token;
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $this->atomicWrite($this->tokenPath, $body, 0600);
    }

    private function ensureCollectorBinary(): void
    {
        $dir = $this->appPath . '/.talaria';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new SiteHostInstallException('Could not create ' . $dir);
        }
        $binary = $dir . '/otelcol-contrib';
        $stamp = $dir . '/otelcol-contrib.version';
        if (
            is_executable($binary)
            && is_file($stamp)
            && trim((string) file_get_contents($stamp)) === self::COLLECTOR_VERSION
        ) {
            return;
        }
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new SiteHostInstallException(
                'otelcol-contrib ' . self::COLLECTOR_VERSION . ' is published for linux amd64',
            );
        }
        $tar = $dir . '/otelcol-contrib.tar.gz';
        $url = 'https://github.com/open-telemetry/opentelemetry-collector-releases/releases/download/v'
            . self::COLLECTOR_VERSION
            . '/otelcol-contrib_' . self::COLLECTOR_VERSION . '_linux_amd64.tar.gz';
        $output = [];
        $code = 0;
        exec(
            'curl -fsSL --retry 2 --max-time 180 -o ' . escapeshellarg($tar) . ' ' . escapeshellarg($url) . ' 2>&1',
            $output,
            $code,
        );
        if ($code !== 0 || !is_file($tar)) {
            @unlink($tar);
            throw new SiteHostInstallException('Could not download otelcol-contrib ' . self::COLLECTOR_VERSION);
        }
        $hash = hash_file('sha256', $tar);
        if ($hash !== self::COLLECTOR_SHA256) {
            @unlink($tar);
            throw new SiteHostInstallException('otelcol-contrib checksum did not match');
        }
        $extracted = [];
        $extractCode = 0;
        exec(
            'tar -xzf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($dir) . ' otelcol-contrib 2>&1',
            $extracted,
            $extractCode,
        );
        @unlink($tar);
        if ($extractCode !== 0 || !is_file($binary)) {
            throw new SiteHostInstallException('Could not unpack otelcol-contrib');
        }
        chmod($binary, 0755);
        $this->atomicWrite($stamp, self::COLLECTOR_VERSION . "\n", 0644);
    }

    private function reloadSupervisor(): void
    {
        $output = [];
        $code = 0;
        exec('supervisorctl update 2>&1', $output, $code);
        if ($code === 0) {
            $output = [];
            exec('supervisorctl restart talaria-otelcol 2>&1', $output, $code);
        }
        if ($code !== 0) {
            $detail = preg_replace(
                '/tal_(live|ping)_[A-Za-z0-9_-]+/',
                '[redacted]',
                substr(implode("\n", $output), 0, 300),
            );
            throw new SiteHostInstallException(
                'supervisorctl update failed: ' . (is_string($detail) ? $detail : ''),
            );
        }
    }

    private static function collectorResource(string $name, string $fallback): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim($value);
    }

    private static function collectorAttribute(string $value, string $label): string
    {
        if (preg_match('/^[A-Za-z0-9_.:@+\-]{1,128}$/', $value) !== 1) {
            throw new SiteHostInstallException($label . ' cannot be written into the collector config');
        }

        return $value;
    }

    private static function supervisordPath(string $path, string $label): string
    {
        $path = rtrim($path, '/');
        if ($path === '' || preg_match('/[\s\'"\r\n]/', $path) === 1) {
            throw new SiteHostInstallException($label . ' cannot be written into supervisord');
        }

        return $path;
    }

    private function atomicWrite(string $path, string $body, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new SiteHostInstallException('Directory does not exist: ' . $directory);
        }
        $temporary = $directory . '/.' . basename($path) . '.talaria.tmp';
        if (file_put_contents($temporary, $body) === false) {
            throw new SiteHostInstallException('Could not write ' . $path);
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new SiteHostInstallException('Could not replace ' . $path);
        }
    }
}
