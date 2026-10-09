<?php

declare(strict_types=1);

namespace Talaria\SilverStripe\SiteHost;

final class PhpStreamCheckInTransport implements CheckInTransport
{
    public function post(string $url, string $apiKey, string $json): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\n",
                'content' => $json,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);
        $status = $http_response_header[0] ?? '';
        $code = 0;
        if (preg_match('/\s(\d{3})\b/', $status, $match) === 1) {
            $code = (int) $match[1];
        }

        return [
            'code' => $code,
            'body' => is_string($body) ? $body : '',
        ];
    }
}
