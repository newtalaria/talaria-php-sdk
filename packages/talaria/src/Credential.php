<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Talaria credential grammar from the naming audit §5.1.
 *
 * API keys: tal_(pk|sk)_(prod|stg|test|dev)_<cell>_<43 base62><6 CRC32 base62>
 * Monitor tokens: tal_mon_<cell>_<43 base62><6 CRC32 base62>
 * Checksum alphabet is 0-9A-Za-z. The CRC32/IEEE covers every character before it.
 */
final class Credential
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const API_KEY = '/^tal_(pk|sk)_(prod|stg|test|dev)_([a-z][a-z0-9]*(?:-[a-z0-9]+)*)_([A-Za-z0-9]{43})([A-Za-z0-9]{6})$/';

    private const MONITOR_TOKEN = '/^tal_mon_([a-z][a-z0-9]*(?:-[a-z0-9]+)*)_([A-Za-z0-9]{43})([A-Za-z0-9]{6})$/';

    private static bool $publishableWarned = false;

    private function __construct(
        public readonly string $value,
        public readonly string $kind,
        public readonly ?string $class,
        public readonly ?string $env,
        public readonly string $cell,
    ) {
    }

    public static function parseApiKey(string $key): self
    {
        $key = trim($key);
        if (preg_match(self::API_KEY, $key, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Talaria apiKey must match tal_(pk|sk)_(prod|stg|test|dev)_<cell>_<43 base62><6 checksum>.',
            );
        }
        $cell = $match[3];
        self::assertCell($cell);
        $material = substr($key, 0, -6);
        if (!hash_equals(self::checksum($material), $match[5])) {
            throw new \InvalidArgumentException('Talaria apiKey checksum is invalid.');
        }

        return new self($key, 'api_key', $match[1], $match[2], $cell);
    }

    public static function parseMonitorToken(string $token): self
    {
        $token = trim($token);
        if (preg_match(self::MONITOR_TOKEN, $token, $match) !== 1) {
            throw new \InvalidArgumentException(
                'Talaria monitor token must match tal_mon_<cell>_<43 base62><6 checksum>.',
            );
        }
        $cell = $match[1];
        self::assertCell($cell);
        $material = substr($token, 0, -6);
        if (!hash_equals(self::checksum($material), $match[3])) {
            throw new \InvalidArgumentException('Talaria monitor token checksum is invalid.');
        }

        return new self($token, 'monitor', null, null, $cell);
    }

    public static function isPublishableKey(string $key): bool
    {
        try {
            return self::parseApiKey($key)->isPublishable();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function isMonitorToken(string $token): bool
    {
        try {
            self::parseMonitorToken($token);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * CRC32/IEEE of $material, written as 6 base62 characters (alphabet 0-9A-Za-z).
     */
    public static function checksum(string $material): string
    {
        $unpacked = unpack('N', hash('crc32b', $material, true));
        if ($unpacked === false) {
            throw new \RuntimeException('Talaria checksum failed.');
        }
        $crc = $unpacked[1];
        $chars = '';
        for ($i = 0; $i < 6; $i++) {
            $chars = self::ALPHABET[$crc % 62] . $chars;
            $crc = intdiv($crc, 62);
        }

        return $chars;
    }

    /**
     * Stable fixture. $random must be 43 base62 characters when provided.
     */
    public static function example(string $class = 'sk', string $env = 'test', string $cell = 'nz1', ?string $random = null): string
    {
        if ($class !== 'pk' && $class !== 'sk') {
            throw new \InvalidArgumentException('Talaria key class must be pk or sk.');
        }
        if (!in_array($env, ['prod', 'stg', 'test', 'dev'], true)) {
            throw new \InvalidArgumentException('Talaria key env must be prod, stg, test, or dev.');
        }
        $random ??= str_repeat('a', 43);
        $material = "tal_{$class}_{$env}_{$cell}_{$random}";

        return self::parseApiKey($material . self::checksum($material))->value;
    }

    public static function exampleMonitor(string $cell = 'nz1', ?string $random = null): string
    {
        $random ??= str_repeat('a', 43);
        $material = "tal_mon_{$cell}_{$random}";

        return self::parseMonitorToken($material . self::checksum($material))->value;
    }

    public function isPublishable(): bool
    {
        return $this->class === 'pk';
    }

    public function isSecret(): bool
    {
        return $this->class === 'sk';
    }

    /**
     * Cloud ingest host for this credential's cell.
     * A `self` cell has no cloud host and must be given TALARIA_URL or the url option.
     */
    public function ingestUrl(): string
    {
        if ($this->cell === 'self') {
            throw new \InvalidArgumentException(
                'Talaria cell "self" has no cloud ingest host. Set TALARIA_URL or the url option.',
            );
        }

        return 'https://ingest-' . $this->cell . '.newtalaria.com';
    }

    public static function warnPublishableOnce(): void
    {
        if (self::$publishableWarned) {
            return;
        }
        self::$publishableWarned = true;
        error_log(
            '[Talaria] A publishable tal_pk_ key is in use on the server SDK. '
            . 'Ingest and flag evaluation work; monitors, releases, and flag definitions require a tal_sk_ key.',
        );
    }

    /**
     * @internal
     */
    public static function resetPublishableWarning(): void
    {
        self::$publishableWarned = false;
    }

    private static function assertCell(string $cell): void
    {
        $length = strlen($cell);
        if ($length < 2 || $length > 24) {
            throw new \InvalidArgumentException('Talaria cell id must be 2–24 characters.');
        }
    }
}
