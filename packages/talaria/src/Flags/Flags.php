<?php

declare(strict_types=1);

namespace Talaria\Flags;

use Talaria\Config;
use Talaria\Identity;

/**
 * Feature-flag client for PHP request lifecycle.
 *
 * Default path: remote `POST /flags/evaluate` with in-process cache for the
 * current request. Call {@see loadDefinitions()} (API key scope
 * `flags:definitions`) to switch to local evaluation — preferred for
 * Silverstripe / Laravel workers that cannot afford per-request RTT.
 *
 * Poll / TTL: {@see maybeRefresh()} re-fetches after {@see $pollIntervalSeconds}
 * (aligned with `getConfig` ttl). Long-lived workers should call it at the
 * start of a request. Mobile clients may not update while backgrounded.
 *
 * Coexistence with LaunchDarkly: dual-running both SDKs is OK. Prefer one
 * source of truth per flag key (or distinct namespaces) so evaluations do
 * not collide.
 */
final class Flags
{
    public const MAX_STAMP_FLAGS = 20;

    private readonly LocalFlagEvaluator $evaluator;

    /** @var array<string, array{key: string, variationKey: string, value: mixed, version: int, reason?: string|null, valueJson?: string|null}> */
    private array $evaluations = [];

    /** @var list<array<string, mixed>> */
    private array $definitions = [];

    private bool $localMode = false;

    private ?string $contextUserId = null;

    private ?string $organizationId = null;

    /** @var array<string, string> */
    private array $attributes = [];

    private ?int $lastFetchedAt = null;

    private int $pollIntervalSeconds = 60;

    /** @var callable(): ?FlagsHttpClient */
    private $transport;

    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        callable $transport,
        ?LocalFlagEvaluator $evaluator = null,
    ) {
        $this->transport = $transport;
        $this->evaluator = $evaluator ?? new LocalFlagEvaluator();
    }

    public function isEnabled(): bool
    {
        return $this->config->enableFlags;
    }

    public function isLocalMode(): bool
    {
        return $this->localMode;
    }

    /**
     * @param array<string, string>|null $attributes
     */
    public function setContext(
        ?string $userId = null,
        ?string $organizationId = null,
        ?array $attributes = null,
        bool $clearUserId = false,
        bool $clearOrganizationId = false,
    ): void {
        if ($clearUserId) {
            $this->contextUserId = null;
        } elseif ($userId !== null) {
            $trimmed = trim($userId);
            $this->contextUserId = $trimmed === '' ? null : $trimmed;
        }
        if ($clearOrganizationId) {
            $this->organizationId = null;
        } elseif ($organizationId !== null) {
            $trimmed = trim($organizationId);
            $this->organizationId = $trimmed === '' ? null : $trimmed;
        }
        if ($attributes !== null) {
            $normalized = [];
            foreach ($attributes as $k => $v) {
                $normalized[(string) $k] = (string) $v;
            }
            $this->attributes = $normalized;
        }
        $this->reload();
    }

    public function setPollIntervalSeconds(int $seconds): void
    {
        $this->pollIntervalSeconds = max(15, min(3600, $seconds));
    }

    /**
     * Re-fetch when TTL elapsed (call at the start of long-lived worker requests).
     */
    public function maybeRefresh(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($this->lastFetchedAt === null
            || (time() - $this->lastFetchedAt) >= $this->pollIntervalSeconds) {
            $this->reload();
        }
    }

    public function reload(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            if ($this->localMode) {
                $this->refreshLocal();
            } else {
                $this->refreshRemote();
            }
        } catch (\Throwable $e) {
            error_log('[Talaria] flags refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * Download definitions and switch to local evaluation.
     * Requires API key scope `flags:definitions` (server/backend keys only).
     *
     * @param list<string>|null $keys
     */
    public function loadDefinitions(?array $keys = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $transport = ($this->transport)();
        if ($transport === null) {
            throw new \RuntimeException('Talaria flags transport unavailable');
        }
        $input = ['__className__' => 'DownloadFlagDefinitionsInput'];
        if ($keys !== null && $keys !== []) {
            $input['keys'] = array_values($keys);
        }
        $raw = $transport->postJson('flags/downloadDefinitions', [
            'input' => $input,
        ], max(0.5, $this->config->httpTimeoutSeconds));
        $this->definitions = $this->parseDefinitions($raw);
        $this->localMode = true;
        $this->applyLocalEvaluations();
        $this->lastFetchedAt = time();
    }

    public function boolVariation(string $key, bool $defaultValue): bool
    {
        $result = $this->resolve($key);
        if ($result === null) {
            return $defaultValue;
        }
        $value = $result['value'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((float) $value) !== 0.0;
        }
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
        }

        return $defaultValue;
    }

    public function stringVariation(string $key, string $defaultValue): string
    {
        $result = $this->resolve($key);
        if ($result === null) {
            return $defaultValue;
        }
        $value = $result['value'];
        if ($value === null) {
            return $defaultValue;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value);

        return is_string($encoded) ? $encoded : $defaultValue;
    }

    public function jsonVariation(string $key, mixed $defaultValue): mixed
    {
        $result = $this->resolve($key);
        if ($result === null) {
            return $defaultValue;
        }

        return $result['value'] ?? $defaultValue;
    }

    /**
     * @return array<string, string> flag key → variationKey
     */
    public function activeFlags(int $max = self::MAX_STAMP_FLAGS): array
    {
        $out = [];
        foreach ($this->evaluations as $key => $result) {
            if (count($out) >= $max) {
                break;
            }
            $variation = (string) ($result['variationKey'] ?? '');
            if ($variation === '') {
                continue;
            }
            $out[$key] = $variation;
        }

        return $out;
    }

    /**
     * Tags for events: `flag.<key>` → variationKey.
     *
     * @return array<string, string>
     */
    public function stampTags(int $max = self::MAX_STAMP_FLAGS): array
    {
        $out = [];
        foreach ($this->activeFlags($max) as $key => $variation) {
            $out['flag.' . $key] = $variation;
        }

        return $out;
    }

    /**
     * Clear request-scoped cache (long-lived workers).
     */
    public function resetRequestState(): void
    {
        $this->evaluations = [];
        $this->contextUserId = null;
        $this->organizationId = null;
        $this->attributes = [];
        // Keep definitions + localMode across requests on long-lived workers.
        if ($this->localMode && $this->definitions !== []) {
            $this->applyLocalEvaluations();
        }
    }

    /**
     * @return array{key: string, variationKey: string, value: mixed, version: int, reason?: string|null, valueJson?: string|null}|null
     */
    private function resolve(string $key): ?array
    {
        $trimmed = trim($key);
        if ($trimmed === '' || !$this->isEnabled()) {
            return null;
        }
        if (isset($this->evaluations[$trimmed])) {
            return $this->evaluations[$trimmed];
        }
        $this->maybeRefresh();
        if ($this->evaluations === []) {
            $this->reload();
        }

        return $this->evaluations[$trimmed] ?? null;
    }

    private function refreshRemote(): void
    {
        $transport = ($this->transport)();
        if ($transport === null) {
            return;
        }
        $input = [
            '__className__' => 'EvaluateFlagsInput',
            'anonymousId' => $this->identity->anonymousId,
        ];
        $userId = $this->effectiveUserId();
        if ($userId !== null) {
            $input['userId'] = $userId;
        }
        if ($this->organizationId !== null) {
            $input['organizationId'] = $this->organizationId;
        }
        if ($this->attributes !== []) {
            $input['attributes'] = $this->attributes;
        }
        $raw = $transport->postJson('flags/evaluate', [
            'input' => $input,
        ], max(0.5, $this->config->httpTimeoutSeconds));
        $this->evaluations = $this->parseEvaluations($raw);
        $this->lastFetchedAt = time();
    }

    private function refreshLocal(): void
    {
        $transport = ($this->transport)();
        if ($transport !== null) {
            try {
                $raw = $transport->postJson('flags/downloadDefinitions', [
                    'input' => ['__className__' => 'DownloadFlagDefinitionsInput'],
                ], max(0.5, $this->config->httpTimeoutSeconds));
                $defs = $this->parseDefinitions($raw);
                if ($defs !== []) {
                    $this->definitions = $defs;
                }
            } catch (\Throwable $e) {
                error_log('[Talaria] flags definition refresh failed: ' . $e->getMessage());
            }
        }
        $this->applyLocalEvaluations();
        $this->lastFetchedAt = time();
    }

    private function applyLocalEvaluations(): void
    {
        $context = [
            'anonymousId' => $this->identity->anonymousId,
            'userId' => $this->effectiveUserId(),
            'organizationId' => $this->organizationId,
            'attributes' => $this->attributes,
        ];
        $list = $this->evaluator->evaluateAll($this->definitions, $context);
        $next = [];
        foreach ($list as $item) {
            $key = (string) ($item['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $next[$key] = $item;
        }
        $this->evaluations = $next;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array{key: string, variationKey: string, value: mixed, version: int, reason?: string|null, valueJson?: string|null}>
     */
    private function parseEvaluations(array $raw): array
    {
        $list = $raw['evaluations'] ?? $raw['flags'] ?? null;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $valueJson = isset($item['valueJson']) && is_string($item['valueJson'])
                ? $item['valueJson']
                : null;
            $value = null;
            if ($valueJson !== null) {
                $decoded = json_decode($valueJson, true);
                $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $valueJson;
            }
            $out[$key] = [
                'key' => $key,
                'variationKey' => (string) ($item['variationKey'] ?? ''),
                'value' => $value,
                'version' => (int) ($item['version'] ?? 0),
                'reason' => isset($item['reason']) && is_string($item['reason']) ? $item['reason'] : null,
                'valueJson' => $valueJson,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    private function parseDefinitions(array $raw): array
    {
        $list = $raw['flags'] ?? $raw['definitions'] ?? null;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['key'])) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function effectiveUserId(): ?string
    {
        if ($this->contextUserId !== null) {
            return $this->contextUserId;
        }
        $fromIdentity = $this->identity->userId;
        if ($fromIdentity !== null && trim($fromIdentity) !== '') {
            return trim($fromIdentity);
        }

        return null;
    }
}
