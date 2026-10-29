<?php

declare(strict_types=1);

namespace Talaria\Transport;

/**
 * Authorization: Bearer is the only credential header Talaria clients send.
 */
final class Bearer
{
    /**
     * @return array<string, string>
     */
    public static function headers(string $credential): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => 'Bearer ' . $credential,
        ];
    }
}
