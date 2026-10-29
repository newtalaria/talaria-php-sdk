<?php

declare(strict_types=1);

namespace Talaria\Transport;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Talaria\Event;
use Talaria\Exception\TransportException;

/**
 * HTTP transport for /v1/events batch ingest.
 */
final class ServerpodHttpTransport implements TransportInterface, \Talaria\Flags\FlagsHttpClient
{
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 3.0,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new GuzzleClient([
            'timeout' => $this->timeoutSeconds,
            'http_errors' => false,
        ]);
    }

    /**
     * @param list<Event> $events
     */
    public function sendBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        $payload = [
            'events' => array_map(static fn (Event $event) => $event->toWire(), $events),
        ];

        $url = rtrim($this->baseUrl, '/') . '/v1/events';

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => Bearer::headers($this->apiKey),
                'json' => $payload,
                'timeout' => $this->timeoutSeconds,
            ]);
        } catch (GuzzleException $e) {
            $status = null;
            $body = '';
            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $status = $response?->getStatusCode();
                $body = (string) ($response?->getBody() ?? '');
            }
            $parsed = IngestError::parse($body);
            $detail = self::formatErrorDetail($body, $parsed);
            $prefix = $status !== null
                ? "Talaria /v1/events failed: HTTP {$status}"
                : 'Talaria /v1/events failed: ' . $e->getMessage();
            throw new TransportException(
                $prefix . ($detail !== '' ? " — {$detail}" : ''),
                $status,
                previous: $e,
                retry: $parsed->retry,
                bodyMessage: $parsed->message,
                problemCode: $parsed->code,
                requestId: $parsed->requestId,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody();
        $parsed = IngestError::parse($body);
        $detail = self::formatErrorDetail($body, $parsed);

        throw new TransportException(
            "Talaria /v1/events failed: HTTP {$status}" . ($detail !== '' ? " — {$detail}" : ''),
            $status,
            retry: $parsed->retry,
            bodyMessage: $parsed->message,
            problemCode: $parsed->code,
            requestId: $parsed->requestId,
        );
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $json, float $timeoutSeconds = 0.2): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $response = $this->http->request('POST', $url, [
            'headers' => Bearer::headers($this->apiKey),
            'json' => $json,
            'timeout' => $timeoutSeconds,
        ]);

        return $this->decode($path, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $path, float $timeoutSeconds = 0.2): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $response = $this->http->request('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'timeout' => $timeoutSeconds,
        ]);

        return $this->decode($path, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Plain JSON client report. Body is the report object, with no input wrapper.
     *
     * @param array<string, mixed> $report
     */
    public function postClientReport(array $report, float $timeoutSeconds = 2.0): void
    {
        $this->postJson('v1/client-reports', $report, $timeoutSeconds);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path, int $status, string $raw): array
    {
        if ($status < 200 || $status >= 300) {
            $parsed = IngestError::parse($raw);
            $detail = self::formatErrorDetail($raw, $parsed);
            throw new TransportException(
                "Talaria {$path} failed: HTTP {$status}" . ($detail !== '' ? " — {$detail}" : ''),
                $status,
                retry: $parsed->retry,
                bodyMessage: $parsed->message,
                problemCode: $parsed->code,
                requestId: $parsed->requestId,
            );
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function formatErrorDetail(string $body, IngestError $parsed): string
    {
        $parts = array_filter([
            $parsed->code,
            $parsed->message,
            $parsed->redirectUrl,
        ]);
        if ($parts !== []) {
            return implode(': ', $parts);
        }

        return substr($body, 0, 400);
    }
}
