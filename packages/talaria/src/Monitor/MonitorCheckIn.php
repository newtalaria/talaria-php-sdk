<?php

declare(strict_types=1);

namespace Talaria\Monitor;

/**
 * Immediate job check-in. Cron and probes post this instead of waiting for the event queue.
 */
final class MonitorCheckIn
{
    /**
     * @param array{
     *   crontab?: string,
     *   timezone?: string,
     *   intervalSeconds?: int,
     *   marginSeconds?: int,
     *   maxRuntimeSeconds?: int,
     *   durationSeconds?: float,
     *   logTail?: string,
     *   resource?: array<string, string>
     * } $schedule
     * @return array<string, mixed>
     */
    public static function payload(string $slug, string $status, array $schedule = []): array
    {
        $input = [
            '__className__' => 'CheckInInput',
            'slug' => $slug,
            'status' => $status,
        ];
        foreach ([
            'crontab',
            'timezone',
            'intervalSeconds',
            'marginSeconds',
            'maxRuntimeSeconds',
            'durationSeconds',
            'logTail',
        ] as $key) {
            if (array_key_exists($key, $schedule) && $schedule[$key] !== null && $schedule[$key] !== '') {
                $input[$key] = $schedule[$key];
            }
        }
        if (isset($schedule['resource']) && is_array($schedule['resource'])) {
            $input['resource'] = $schedule['resource'];
        }

        return ['input' => $input];
    }
}
