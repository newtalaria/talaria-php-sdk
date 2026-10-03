<?php

declare(strict_types=1);

namespace Talaria\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Talaria\Laravel\Browser\BrowserScript;

/**
 * Inserts the browser SDK into HTML responses on the web middleware group.
 */
final class BrowserMiddleware
{
    public function __construct(private readonly BrowserScript $script)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = $response->getContent();
        if (!is_string($html) || $html === '' || str_contains($html, '@newtalaria/browser@')) {
            return $response;
        }

        $user = null;
        if (method_exists($request, 'user')) {
            $candidate = $request->user();
            if ($candidate instanceof \Illuminate\Contracts\Auth\Authenticatable) {
                $user = $candidate;
            }
        }

        $snippet = $this->script->html($user);
        if ($snippet === null) {
            return $response;
        }

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $snippet . '</head>', $html, 1) ?? $html;
        } else {
            $html = $snippet . $html;
        }
        $response->setContent($html);

        return $response;
    }
}
