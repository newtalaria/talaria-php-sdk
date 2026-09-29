<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Connect\MySQLDatabase;
use Talaria\TalariaClient;

/**
 * CLIENT/db spans around Silverstripe MySQL queries. Identical statements under
 * one parent roll into one span with a repeat count. No-ops when tracing is off.
 */
class TracingMySQLDatabase extends MySQLDatabase
{
    /**
     * @param mixed $sql
     * @param mixed $errorLevel
     * @return mixed
     */
    public function query($sql, $errorLevel = E_USER_ERROR)
    {
        return $this->withQuerySpan((string) $sql, function () use ($sql, $errorLevel) {
            return parent::query($sql, $errorLevel);
        });
    }

    /**
     * @param mixed $sql
     * @param mixed $parameters
     * @param mixed $errorLevel
     * @return mixed
     */
    public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR)
    {
        return $this->withQuerySpan((string) $sql, function () use ($sql, $parameters, $errorLevel) {
            return parent::preparedQuery($sql, $parameters, $errorLevel);
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withQuerySpan(string $sql, callable $callback): mixed
    {
        $client = self::client();
        if ($client === null || !$client->getConfig()->enableTracing) {
            return $callback();
        }

        $root = $client->getTracer()->rootSpan();
        if ($root === null) {
            // Boot / session queries before HTTPMiddleware would become their own traces.
            return $callback();
        }

        return $client->recordQuery($sql, 'mysql', $callback);
    }

    private static function client(): ?TalariaClient
    {
        if (!class_exists(Injector::class)) {
            return null;
        }

        try {
            $client = Injector::inst()->get(TalariaClient::class);

            return $client instanceof TalariaClient ? $client : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
