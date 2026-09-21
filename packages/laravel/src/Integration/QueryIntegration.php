<?php

declare(strict_types=1);

namespace Talaria\Laravel\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;
use Talaria\Tracing\SqlSanitizer;

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
            $span = $this->client->startSpan(
                SqlSanitizer::spanName($sql),
                SpanKind::Client,
                array_merge(SqlSanitizer::attributes($sql, $system), [
                    'db.query.duration_ms' => (string) $event->time,
                ]),
            );
            $span->setStatus(SpanStatus::Ok);
            $span->end();
            $this->client->addBreadcrumb([
                'type' => 'query',
                'category' => 'db',
                'message' => SqlSanitizer::operation($sql),
                'level' => 'info',
                'data' => ['db.system.name' => $system],
            ]);
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
