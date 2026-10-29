<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Monitor\MonitorCheckIn;

final class MonitorCheckInTest extends TestCase
{
    public function testPayloadCarriesScheduleAndStatus(): void
    {
        $payload = MonitorCheckIn::payload('process-job-queue', 'in_progress', [
            'crontab' => '* * * * *',
            'timezone' => 'Pacific/Auckland',
            'maxRuntimeSeconds' => 55,
        ]);

        self::assertArrayNotHasKey('input', $payload);
        self::assertArrayNotHasKey('__className__', $payload);
        self::assertSame('process-job-queue', $payload['slug']);
        self::assertSame('in_progress', $payload['status']);
        self::assertSame('Pacific/Auckland', $payload['timezone']);
        self::assertSame(55, $payload['maxRuntimeSeconds']);
        self::assertArrayNotHasKey('logTail', $payload);
    }
}
