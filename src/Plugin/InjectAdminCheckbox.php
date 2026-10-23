<?php

namespace Chooka321\Deluxe;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InjectAdminCheckbox
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $contentType = $response->headers->get('Content-Type') ?? '';
        if (! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || strpos($content, '</head>') === false) {
            return $response;
        }

        // Only inject on the React admin SPA loader (identified by <div id="root">)
        if (strpos($content, '<div id="root">') === false) {
            return $response;
        }

        $inject = @file_get_contents(__DIR__ . '/admin-injection.html');
        if (! $inject) {
            return $response;
        }

        $response->setContent(str_replace('</head>', $inject . '</head>', $content));
        return $response;
    }
}
