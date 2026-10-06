<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Psr\Http\Message\StreamInterface;

/**
 * OpenAI and Anthropic HTTP calls as OpenTelemetry GenAI spans.
 *
 * Prompt and completion text are not attributes. Only model, operation,
 * token counts, and status are kept.
 */
final class ModelHttp
{
    public const BODY_CAP = 1048576;

    /**
     * @return array{provider: string, operation: string, host: string}|null
     */
    public static function classify(string $method, string $host, string $path): ?array
    {
        if (strtoupper($method) !== 'POST') {
            return null;
        }
        $host = strtolower($host);
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        if ($host === 'api.openai.com') {
            $operation = match ($path) {
                '/v1/chat/completions', '/v1/responses' => 'chat',
                '/v1/completions' => 'text_completion',
                '/v1/embeddings' => 'embeddings',
                default => null,
            };
            if ($operation === null) {
                return null;
            }

            return ['provider' => 'openai', 'operation' => $operation, 'host' => $host];
        }
        if ($host === 'api.anthropic.com' && $path === '/v1/messages') {
            return ['provider' => 'anthropic', 'operation' => 'chat', 'host' => $host];
        }

        return null;
    }

    public static function spanName(string $operation, ?string $model): string
    {
        $model = self::clip($model);

        return $model !== null ? $operation . ' ' . $model : $operation;
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{model: ?string, stream: bool}
     */
    public static function requestFields(?array $json): array
    {
        if ($json === null) {
            return ['model' => null, 'stream' => false];
        }

        return [
            'model' => self::clip(is_string($json['model'] ?? null) ? $json['model'] : null),
            'stream' => ($json['stream'] ?? false) === true,
        ];
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array{responseModel: ?string, inputTokens: ?string, outputTokens: ?string}
     */
    public static function usageFields(?array $json): array
    {
        $empty = ['responseModel' => null, 'inputTokens' => null, 'outputTokens' => null];
        if ($json === null) {
            return $empty;
        }
        $usage = $json['usage'] ?? null;
        $input = null;
        $output = null;
        if (is_array($usage)) {
            $input = self::token($usage['input_tokens'] ?? null) ?? self::token($usage['prompt_tokens'] ?? null);
            $output = self::token($usage['output_tokens'] ?? null) ?? self::token($usage['completion_tokens'] ?? null);
        }

        return [
            'responseModel' => self::clip(is_string($json['model'] ?? null) ? $json['model'] : null),
            'inputTokens' => $input,
            'outputTokens' => $output,
        ];
    }

    /**
     * Read JSON and rewind a seekable body. Oversized or unreadable bodies return null.
     *
     * @return array<string, mixed>|null
     */
    public static function readJson(StreamInterface $body): ?array
    {
        if (!$body->isSeekable()) {
            return null;
        }
        $size = $body->getSize();
        if ($size !== null && $size > self::BODY_CAP) {
            return null;
        }
        $raw = $body->getContents();
        $body->rewind();
        if (strlen($raw) > self::BODY_CAP) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function exceptionType(\Throwable $error): string
    {
        $class = $error::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private static function clip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return strlen($value) > 256 ? substr($value, 0, 256) : $value;
    }

    private static function token(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        if (!is_finite((float) $value) || $value < 0) {
            return null;
        }

        return (string) (int) $value;
    }
}
