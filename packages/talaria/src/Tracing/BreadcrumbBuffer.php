<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Context\RuntimeContext;

/**
 * Ring buffer of recent breadcrumbs attached to error events.
 *
 * @phpstan-type Breadcrumb array{
 *   __className__: string,
 *   timestamp: string,
 *   type: string,
 *   category?: string,
 *   message?: string,
 *   level?: string,
 *   data?: array<string, string>
 * }
 */
final class BreadcrumbBuffer
{
    public const DEFAULT_CAPACITY = 50;

    /** Query crumbs cannot grow past this, and cannot evict other crumbs. */
    public const MAX_QUERY = 15;

    /** Non-query crumbs. Together with {@see MAX_QUERY} this fills the buffer. */
    public const MAX_OTHER = 35;

    /** @var list<Breadcrumb> */
    private array $items = [];

    public function __construct(private readonly int $capacity = self::DEFAULT_CAPACITY)
    {
    }

    /**
     * @param array{
     *   timestamp?: string,
     *   type?: string,
     *   category?: string|null,
     *   message?: string|null,
     *   level?: string|null,
     *   data?: array<string, mixed>
     * } $breadcrumb
     */
    public function add(array $breadcrumb): void
    {
        $item = [
            '__className__' => 'BreadcrumbDto',
            'timestamp' => is_string($breadcrumb['timestamp'] ?? null) && $breadcrumb['timestamp'] !== ''
                ? $breadcrumb['timestamp']
                : RuntimeContext::isoTimestamp(),
            'type' => is_string($breadcrumb['type'] ?? null) && $breadcrumb['type'] !== ''
                ? $breadcrumb['type']
                : 'default',
        ];

        if (is_string($breadcrumb['category'] ?? null) && $breadcrumb['category'] !== '') {
            $item['category'] = $breadcrumb['category'];
        }
        if (is_string($breadcrumb['message'] ?? null) && $breadcrumb['message'] !== '') {
            $item['message'] = $breadcrumb['message'];
        }
        if (is_string($breadcrumb['level'] ?? null) && $breadcrumb['level'] !== '') {
            $item['level'] = $breadcrumb['level'];
        }

        $data = self::normalizeData(is_array($breadcrumb['data'] ?? null) ? $breadcrumb['data'] : []);
        if ($data !== []) {
            $item['data'] = $data;
        }

        if (($item['type'] ?? '') === 'query') {
            $this->trim('query', $this->queryCap() - 1);
        } else {
            $this->trim(null, $this->otherCap() - 1);
        }
        $this->items[] = $item;
    }

    private function queryCap(): int
    {
        if ($this->capacity >= self::DEFAULT_CAPACITY) {
            return self::MAX_QUERY;
        }

        return min(self::MAX_QUERY, $this->capacity);
    }

    private function otherCap(): int
    {
        return max(0, min(self::MAX_OTHER, $this->capacity - $this->queryCap()));
    }

    /**
     * Drop the oldest crumbs of $type (or every non-query crumb when $type is null)
     * until at most $max remain.
     */
    private function trim(?string $type, int $max): void
    {
        if ($max < 0) {
            $max = 0;
        }
        $matched = [];
        foreach ($this->items as $index => $existing) {
            $isQuery = ($existing['type'] ?? '') === 'query';
            $hit = $type === null ? !$isQuery : $isQuery;
            if ($hit) {
                $matched[] = $index;
            }
        }
        $overflow = count($matched) - $max;
        if ($overflow <= 0) {
            return;
        }
        $drop = array_flip(array_slice($matched, 0, $overflow));
        $kept = [];
        foreach ($this->items as $index => $existing) {
            if (!isset($drop[$index])) {
                $kept[] = $existing;
            }
        }
        $this->items = $kept;
    }

    /**
     * @return list<Breadcrumb>
     */
    public function snapshot(): array
    {
        return array_values($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function normalizeData(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';
            } elseif (is_scalar($value) || $value instanceof \Stringable) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
