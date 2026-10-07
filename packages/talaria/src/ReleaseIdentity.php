<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Builds `<ref>@<shortsha>` from TALARIA_* or CI environment variables.
 *
 * Does not run git. An explicit release wins. CI is used only when both
 * release and commit SHA are empty.
 */
final class ReleaseIdentity
{
    /**
     * @param array<string, string|null> $env
     * @return array{release: ?string, commitSha: ?string, releaseRefKind: ?string}
     */
    public static function resolve(?string $release, ?string $commitSha, array $env): array
    {
        $explicitRelease = self::nonEmpty($release) ?? self::nonEmpty($env['TALARIA_RELEASE'] ?? null);
        $explicitSha = self::nonEmpty($commitSha) ?? self::nonEmpty($env['TALARIA_COMMIT_SHA'] ?? null);
        $ci = self::fromCi($env);

        if ($explicitRelease !== null || $explicitSha !== null) {
            $refKind = null;
            if (
                $explicitRelease !== null
                && $ci['release'] !== null
                && $explicitRelease === $ci['release']
            ) {
                $refKind = $ci['releaseRefKind'];
            }

            return [
                'release' => $explicitRelease,
                'commitSha' => $explicitSha,
                'releaseRefKind' => $refKind,
            ];
        }

        return $ci;
    }

    /**
     * @return array<string, string|null>
     */
    public static function environment(): array
    {
        $keys = [
            'TALARIA_RELEASE',
            'TALARIA_COMMIT_SHA',
            'GITHUB_REF_NAME',
            'GITHUB_SHA',
            'GITHUB_REF_TYPE',
            'CI_COMMIT_REF_NAME',
            'CI_COMMIT_SHA',
            'CI_COMMIT_TAG',
        ];
        $env = [];
        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            $env[$key] = is_string($value) ? $value : null;
        }

        return $env;
    }

    /**
     * @param array<string, string|null> $env
     * @return array{release: ?string, commitSha: ?string, releaseRefKind: ?string}
     */
    private static function fromCi(array $env): array
    {
        $empty = ['release' => null, 'commitSha' => null, 'releaseRefKind' => null];

        $githubRef = self::nonEmpty($env['GITHUB_REF_NAME'] ?? null);
        $githubSha = self::nonEmpty($env['GITHUB_SHA'] ?? null);
        if ($githubRef !== null && $githubSha !== null && strlen($githubSha) >= 7) {
            $type = self::nonEmpty($env['GITHUB_REF_TYPE'] ?? null);
            $kind = $type === 'tag' || $type === 'branch' ? $type : null;

            return [
                'release' => $githubRef . '@' . substr($githubSha, 0, 7),
                'commitSha' => $githubSha,
                'releaseRefKind' => $kind,
            ];
        }

        $gitlabRef = self::nonEmpty($env['CI_COMMIT_REF_NAME'] ?? null);
        $gitlabSha = self::nonEmpty($env['CI_COMMIT_SHA'] ?? null);
        if ($gitlabRef !== null && $gitlabSha !== null && strlen($gitlabSha) >= 7) {
            $tag = self::nonEmpty($env['CI_COMMIT_TAG'] ?? null);

            return [
                'release' => $gitlabRef . '@' . substr($gitlabSha, 0, 7),
                'commitSha' => $gitlabSha,
                'releaseRefKind' => $tag !== null ? 'tag' : 'branch',
            ];
        }

        return $empty;
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
