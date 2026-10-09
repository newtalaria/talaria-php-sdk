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

        self::assertSame('CheckInInput', $payload['input']['__className__']);
        self::assertSame('process-job-queue', $payload['input']['slug']);
        self::assertSame('in_progress', $payload['input']['status']);
        self::assertSame('Pacific/Auckland', $payload['input']['timezone']);
        self::assertSame(55, $payload['input']['maxRuntimeSeconds']);
        self::assertArrayNotHasKey('logTail', $payload['input']);
    }
}
