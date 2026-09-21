<?php

declare(strict_types=1);

namespace Talaria\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class TracingMiddleware
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!$this->client->getConfig()->enableTracing) {
            try {
                return $next($request);
            } finally {
                $this->client->flush();
            }
        }

        $method = strtoupper($request->getMethod());
        $route = self::routeName($request);
        $name = $method . ' ' . $route;
        $span = $this->client->startTransaction($name, SpanKind::Server, [
            'http.request.method' => $method,
            'http.route' => $route,
            'url.path' => '/' . ltrim($request->path(), '/'),
        ]);
        $this->client->getTracer()->setRequestAttributes([
            'http.request.method' => $method,
            'http.route' => $route,
        ]);
        $this->client->addBreadcrumb([
            'type' => 'http',
            'category' => 'http',
            'message' => $name,
            'level' => 'info',
            'data' => ['method' => $method, 'url' => $route],
        ]);

        try {
            /** @var Response $response */
            $response = $next($request);
            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', (string) $status);
            if ($status >= 500) {
                $span->setStatus(SpanStatus::Error, 'HTTP ' . $status);
                $this->client->getTracer()->markError('HTTP ' . $status);
            } elseif ($span->getStatus() !== SpanStatus::Error->value) {
                $span->setStatus(SpanStatus::Ok);
            }

            return $response;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            $this->client->getTracer()->markError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $this->client->flush();
        }
    }

    public static function routeName(Request $request): string
    {
        $route = $request->route();
        if ($route !== null) {
            $named = method_exists($route, 'getName') ? $route->getName() : null;
            if (is_string($named) && $named !== '') {
                return $named;
            }
            $uri = method_exists($route, 'uri') ? $route->uri() : null;
            if (is_string($uri) && $uri !== '') {
                return '/' . ltrim($uri, '/');
            }
        }

        return '/' . ltrim($request->path(), '/');
    }
}
