<?php

declare(strict_types=1);

namespace SilverStripe\Core\Config;

/**
 * In-memory stand-in for Silverstripe's Configurable config bag.
 * Used only when silverstripe/framework is not installed.
 */
final class MemoryConfig
{
    /** @var array<string, array<string, mixed>> */
    private static array $store = [];

    public function __construct(private readonly string $class)
    {
    }

    public static function forClass(string $class): self
    {
        return new self($class);
    }

    public function get(string $name): mixed
    {
        return self::$store[$this->class][$name] ?? null;
    }

    public function set(string $name, mixed $value): self
    {
        self::$store[$this->class][$name] = $value;

        return $this;
    }

    public static function reset(): void
    {
        self::$store = [];
    }
}

trait Configurable
{
    public static function config(): MemoryConfig
    {
        return MemoryConfig::forClass(static::class);
    }
}
