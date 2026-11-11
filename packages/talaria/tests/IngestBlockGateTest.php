<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Transport\IngestBlockGate;
use Talaria\Transport\IngestError;

final class IngestBlockGateTest extends TestCase
{
    public function testClamp(): void
    {
        self::assertSame(IngestBlockGate::MIN_RETRY_SECONDS, IngestBlockGate::clampRetryAfter(60));
        self::assertSame(IngestBlockGate::MIN_RETRY_SECONDS, IngestBlockGate::clampRetryAfter(null));
        self::assertSame(IngestBlockGate::MAX_RETRY_SECONDS, IngestBlockGate::clampRetryAfter(48 * 60 * 60));
        self::assertSame(3600, IngestBlockGate::clampRetryAfter(3600));
    }

    public function testPauseFromErrorAndWarnOnce(): void
    {
        $now = 1_000_000;
        $warnings = [];
        $gate = new IngestBlockGate(static fn () => $now, static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });
        $error = IngestError::parse(json_encode([
            'status' => 402,
            'code' => 'ingest_blocked',
            'reason' => 'org_unpaid',
            'retryAfterSeconds' => 900,
            'detail' => 'Ingest is stopped for this organisation.',
        ], JSON_THROW_ON_ERROR));
        self::assertTrue($error->isIngestBlocked());
        self::assertFalse($error->isPermanent());
        self::assertTrue($gate->applyFromError($error));
        self::assertTrue($gate->isBlocked());
        self::assertSame('org_unpaid', $gate->reason());
        self::assertSame(900, $gate->remainingSeconds());
        self::assertCount(1, $warnings);
        $gate->applyFromError(IngestError::parse(json_encode([
            'code' => 'ingest_blocked',
            'retryAfterSeconds' => 60,
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(900, $gate->remainingSeconds());
        self::assertCount(1, $warnings);
    }

    public function testDocumentCanPauseAndResume(): void
    {
        $now = 5;
        $gate = new IngestBlockGate(static fn () => $now, static function (string $m): void {});
        $gate->applyFromDocument(['state' => 'blocked', 'reason' => 'neglected', 'retryAfterSeconds' => 3600]);
        self::assertTrue($gate->isBlocked());
        $gate->applyFromDocument(['state' => 'ok']);
        self::assertFalse($gate->isBlocked());
    }
}
