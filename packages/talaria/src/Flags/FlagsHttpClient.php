<?php

declare(strict_types=1);

namespace Talaria\Flags;

/**
 * Minimal HTTP surface used by {@see Flags} (evaluate / downloadDefinitions).
 */
interface FlagsHttpClient
{
    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $json, float $timeoutSeconds = 0.2): array;
}
