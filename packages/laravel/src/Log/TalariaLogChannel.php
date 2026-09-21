<?php

declare(strict_types=1);

namespace Talaria\Laravel\Log;

use Psr\Log\LoggerInterface;
use Talaria\TalariaClient;

final class TalariaLogChannel
{
    /**
     * @param array<string, mixed> $config
     */
    public function __invoke(array $config): LoggerInterface
    {
        $client = app(TalariaClient::class);
        $options = [
            'tags' => ['channel' => is_string($config['name'] ?? null) ? $config['name'] : 'talaria'],
        ];
        if (isset($config['level']) && is_string($config['level'])) {
            $options['minLevel'] = $config['level'];
        }

        return $client->logger($options);
    }
}
