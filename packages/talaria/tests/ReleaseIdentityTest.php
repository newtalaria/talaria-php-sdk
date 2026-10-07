<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\ReleaseIdentity;

final class ReleaseIdentityTest extends TestCase
{
    public function testExplicitReleaseWins(): void
    {
        $resolved = ReleaseIdentity::resolve('1.4.2', null, [
            'GITHUB_REF_NAME' => 'main',
            'GITHUB_SHA' => 'a8f31c2e4b6d8901234567890abcdef123456789',
            'GITHUB_REF_TYPE' => 'branch',
        ]);

        self::assertSame('1.4.2', $resolved['release']);
        self::assertNull($resolved['commitSha']);
        self::assertNull($resolved['releaseRefKind']);
    }

    public function testGitHubActionsFillsRefAndFullSha(): void
    {
        $resolved = ReleaseIdentity::resolve(null, null, [
            'GITHUB_REF_NAME' => 'feature/new-checkout',
            'GITHUB_SHA' => 'f32a991e4b6d8901234567890abcdef123456789',
            'GITHUB_REF_TYPE' => 'branch',
        ]);

        self::assertSame('feature/new-checkout@f32a991', $resolved['release']);
        self::assertSame('f32a991e4b6d8901234567890abcdef123456789', $resolved['commitSha']);
        self::assertSame('branch', $resolved['releaseRefKind']);
    }

    public function testGitLabTag(): void
    {
        $resolved = ReleaseIdentity::resolve('', '', [
            'CI_COMMIT_REF_NAME' => 'v1.4.2',
            'CI_COMMIT_SHA' => 'a8f31c2e4b6d8901234567890abcdef123456789',
            'CI_COMMIT_TAG' => 'v1.4.2',
        ]);

        self::assertSame('v1.4.2@a8f31c2', $resolved['release']);
        self::assertSame('tag', $resolved['releaseRefKind']);
    }

    public function testEmptyWhenCiIsMissing(): void
    {
        $resolved = ReleaseIdentity::resolve(null, null, []);

        self::assertNull($resolved['release']);
        self::assertNull($resolved['commitSha']);
    }
}
