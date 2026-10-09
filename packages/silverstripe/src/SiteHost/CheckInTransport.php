<?php

declare(strict_types=1);

namespace Talaria\SilverStripe\SiteHost;

interface CheckInTransport
{
    /**
     * @return array{code: int, body: string}
     */
    public function post(string $url, string $apiKey, string $json): array;
}
