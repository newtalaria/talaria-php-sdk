<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;

final class QueryIntegration
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function register(): void
    {
        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            if (!$this->client->getConfig()->enableTracing) {
                return;
            }

            $sql = (string) $event->sql;
            $system = self::systemName((string) $event->connectionName);
            $this->client->recordFinishedQuery(
                $sql,
                $system,
                (float) $event->time,
                attributes: [
                    'db.query.duration_ms' => (string) $event->time,
                ],
            );
        });
    }

    private static function systemName(string $connection): string
    {
        return match (strtolower($connection)) {
            'pgsql', 'postgres', 'postgresql' => 'postgresql',
            'sqlite' => 'sqlite',
            'sqlsrv' => 'mssql',
            default => 'mysql',
        };
    }
}
