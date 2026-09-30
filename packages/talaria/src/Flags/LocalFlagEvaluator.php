<?php

declare(strict_types=1);

namespace Talaria\Flags;

/**
 * Deterministic local flag evaluation — same algorithm as the Talaria server
 * `FlagEvaluator` and Dart `LocalFlagEvaluator`.
 *
 * Bucketing: sha256("$flagKey\n$bucketKey") first 4 bytes big-endian % 100
 * (0–99 inclusive). Never uses sessionId.
 *
 * Coexistence with LaunchDarkly: dual-running both SDKs in one process is fine.
 * Prefer one system as the source of truth for a given flag key, or map
 * Talaria keys to a distinct namespace so evaluations do not collide.
 */
final class LocalFlagEvaluator
{
    /**
     * @param array{
     *   key: string,
     *   kind?: string,
     *   variations: list<array{key: string, valueJson?: string|null, value?: mixed, name?: string|null}>,
     *   rules?: list<array<string, mixed>>,
     *   defaultVariationKey: string,
     *   offVariationKey: string,
     *   enabled?: bool,
     *   version?: int,
     * } $flag
     * @param array{
     *   anonymousId?: string|null,
     *   userId?: string|null,
     *   organizationId?: string|null,
     *   attributes?: array<string, string>,
     * } $context
     * @return array{key: string, variationKey: string, value: mixed, version: int, reason: string, valueJson: ?string}
     */
    public function evaluate(array $flag, array $context): array
    {
        $enabled = ($flag['enabled'] ?? true) === true;
        if (!$enabled) {
            return $this->serve($flag, (string) $flag['offVariationKey'], 'off');
        }

        $rules = is_array($flag['rules'] ?? null) ? $flag['rules'] : [];
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ($this->matches((string) $flag['key'], $rule, $context)) {
                return $this->serve($flag, (string) ($rule['variationKey'] ?? ''), 'rule:' . $i);
            }
        }

        return $this->serve($flag, (string) $flag['defaultVariationKey'], 'default');
    }

    /**
     * @param list<array<string, mixed>> $flags
     * @param array<string, mixed> $context
     * @param list<string>|null $keys
     * @return list<array{key: string, variationKey: string, value: mixed, version: int, reason: string, valueJson: ?string}>
     */
    public function evaluateAll(array $flags, array $context, ?array $keys = null): array
    {
        $filter = null;
        if ($keys !== null && $keys !== []) {
            $filter = array_fill_keys($keys, true);
        }
        $out = [];
        foreach ($flags as $flag) {
            if (!is_array($flag) || !isset($flag['key'])) {
                continue;
            }
            $key = (string) $flag['key'];
            if ($filter !== null && !isset($filter[$key])) {
                continue;
            }
            $out[] = $this->evaluate($flag, $context);
        }

        return $out;
    }

    /**
     * Returns 0–99 inclusive. Stable for (flagKey, bucketKey).
     */
    public static function bucket(string $flagKey, string $bucketKey): int
    {
        $digest = hash('sha256', $flagKey . "\n" . $bucketKey, true);
        $n = unpack('N', substr($digest, 0, 4))[1];

        return abs($n) % 100;
    }

    /**
     * @param array<string, mixed> $flag
     * @return array{key: string, variationKey: string, value: mixed, version: int, reason: string, valueJson: ?string}
     */
    private function serve(array $flag, string $variationKey, string $reason): array
    {
        $variation = $this->variationByKey($flag, $variationKey)
            ?? $this->variationByKey($flag, (string) ($flag['offVariationKey'] ?? ''))
            ?? $this->firstVariation($flag);

        $valueJson = null;
        $value = null;
        $resolvedKey = $variationKey;
        if ($variation !== null) {
            $resolvedKey = (string) ($variation['key'] ?? $variationKey);
            if (array_key_exists('valueJson', $variation) && is_string($variation['valueJson'])) {
                $valueJson = $variation['valueJson'];
                $decoded = json_decode($valueJson, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $valueJson;
            } elseif (array_key_exists('value', $variation)) {
                $value = $variation['value'];
                $valueJson = json_encode($value);
            }
        }

        return [
            'key' => (string) $flag['key'],
            'variationKey' => $resolvedKey,
            'value' => $value,
            'version' => (int) ($flag['version'] ?? 0),
            'reason' => $reason,
            'valueJson' => $valueJson,
        ];
    }

    /**
     * @param array<string, mixed> $flag
     * @return array<string, mixed>|null
     */
    private function variationByKey(array $flag, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        $variations = is_array($flag['variations'] ?? null) ? $flag['variations'] : [];
        foreach ($variations as $variation) {
            if (is_array($variation) && ($variation['key'] ?? null) === $key) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $flag
     * @return array<string, mixed>|null
     */
    private function firstVariation(array $flag): ?array
    {
        $variations = is_array($flag['variations'] ?? null) ? $flag['variations'] : [];
        foreach ($variations as $variation) {
            if (is_array($variation)) {
                return $variation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private function matches(string $flagKey, array $rule, array $context): bool
    {
        $kind = (string) ($rule['kind'] ?? 'always');
        return match ($kind) {
            'always' => true,
            'percentUsers' => $this->matchPercent($flagKey, $rule, $this->userBucketKey($context)),
            'percentOrgs' => $this->matchPercent(
                $flagKey,
                $rule,
                $this->nullableString($context['organizationId'] ?? null),
            ),
            'userList' => $this->matchList($rule, $this->userBucketKey($context)),
            'orgList' => $this->matchList(
                $rule,
                $this->nullableString($context['organizationId'] ?? null),
            ),
            'attributeMatch' => $this->matchAttribute($rule, $context),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function matchPercent(string $flagKey, array $rule, ?string $bucketKey): bool
    {
        if ($bucketKey === null) {
            return false;
        }
        $pct = (int) ($rule['percent'] ?? 0);
        if ($pct <= 0) {
            return false;
        }
        if ($pct >= 100) {
            return true;
        }

        return self::bucket($flagKey, $bucketKey) < $pct;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function matchList(array $rule, ?string $id): bool
    {
        if ($id === null) {
            return false;
        }
        $exclude = $this->stringList($rule['excludeIds'] ?? null);
        if (in_array($id, $exclude, true)) {
            return false;
        }
        $include = $this->stringList($rule['includeIds'] ?? null);
        if ($include === []) {
            return false;
        }

        return in_array($id, $include, true);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $context
     */
    private function matchAttribute(array $rule, array $context): bool
    {
        $attrKey = $this->nullableString($rule['attributeKey'] ?? null);
        if ($attrKey === null) {
            return false;
        }
        $attributes = is_array($context['attributes'] ?? null) ? $context['attributes'] : [];
        if (!isset($attributes[$attrKey]) || !is_string($attributes[$attrKey])) {
            return false;
        }
        $actual = $attributes[$attrKey];
        $values = $this->stringList($rule['attributeValues'] ?? null);
        $op = (string) ($rule['attributeOp'] ?? 'equals');

        return match ($op) {
            'equals' => $values !== [] && $values[0] === $actual,
            'inList' => in_array($actual, $values, true),
            'contains' => (static function () use ($values, $actual): bool {
                foreach ($values as $v) {
                    if (str_contains($actual, $v)) {
                        return true;
                    }
                }

                return false;
            })(),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private function userBucketKey(array $context): ?string
    {
        return $this->nullableString($context['userId'] ?? null)
            ?? $this->nullableString($context['anonymousId'] ?? null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
