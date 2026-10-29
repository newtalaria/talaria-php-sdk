<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Transport\IngestError;

final class IngestErrorTest extends TestCase
{
    public function testParseProblemJson(): void
    {
        $parsed = IngestError::parse(json_encode([
            'type' => 'https://newtalaria.com/docs/reference/errors#invalid_key',
            'title' => 'Invalid key',
            'status' => 401,
            'detail' => 'Invalid API key',
            'code' => 'invalid_key',
            'requestId' => 'req_abc',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('invalid_key', $parsed->code);
        self::assertSame('Invalid API key', $parsed->message);
        self::assertSame('req_abc', $parsed->requestId);
        self::assertFalse($parsed->retry);
        self::assertTrue($parsed->isPermanent());
        self::assertTrue($parsed->isGlobalCredentialFailure());
    }

    public function testWrongCellCarriesRedirect(): void
    {
        $parsed = IngestError::parse(json_encode([
            'type' => 'https://newtalaria.com/docs/reference/errors#wrong_cell',
            'title' => 'Wrong cell',
            'status' => 421,
            'detail' => 'Send this key to its cell',
            'code' => 'wrong_cell',
            'cell' => 'eu1',
            'url' => 'https://ingest-eu1.newtalaria.com',
            'requestId' => 'req_cell',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('eu1', $parsed->cell);
        self::assertSame('https://ingest-eu1.newtalaria.com', $parsed->redirectUrl);
        self::assertTrue($parsed->isPermanent());
        self::assertFalse($parsed->isGlobalCredentialFailure());
    }

    public function testQuotaIsNotPermanent(): void
    {
        $parsed = IngestError::parse(json_encode([
            'status' => 429,
            'code' => 'quota_exceeded',
            'detail' => 'quota exceeded',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed->retry);
        self::assertFalse($parsed->isPermanent());
    }

    public function testInsufficientScopeIsScopeOnly(): void
    {
        $parsed = IngestError::parse(json_encode([
            'status' => 403,
            'code' => 'insufficient_scope',
            'detail' => 'events:write is required',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed->isPermanent());
        self::assertTrue($parsed->isScopeOnly());
        self::assertFalse($parsed->isGlobalCredentialFailure());
    }

    public function testNotFoundIsPermanent(): void
    {
        $parsed = IngestError::parse(json_encode([
            'status' => 404,
            'code' => 'not_found',
            'detail' => 'Project not found',
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($parsed->isPermanent());
        self::assertTrue($parsed->isGlobalCredentialFailure());
    }
}
