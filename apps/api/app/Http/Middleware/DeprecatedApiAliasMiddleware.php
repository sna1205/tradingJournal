<?php

namespace App\Http\Middleware;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeprecatedApiAliasMiddleware
{
    private const SUNSET_DAYS_AHEAD = 45;

    public function handle(Request $request, Closure $next, string $canonicalTemplate): Response
    {
        $canonicalPath = $this->resolveCanonicalPath($request, $canonicalTemplate);
        $sunsetAt = CarbonImmutable::now('UTC')->addDays(self::SUNSET_DAYS_AHEAD);

        Log::warning('Deprecated API alias used.', [
            'path' => '/' . ltrim($request->path(), '/'),
            'method' => strtoupper($request->method()),
            'canonical_path' => $canonicalPath,
            'sunset' => $sunsetAt->toRfc7231String(),
        ]);

        /** @var Response $response */
        $response = $next($request);

        $canonicalUrl = $this->canonicalUrl($request, $canonicalPath);
        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', $sunsetAt->toRfc7231String());
        $response->headers->set('Link', "<{$canonicalUrl}>; rel=\"successor-version\"");

        return $response;
    }

    private function resolveCanonicalPath(Request $request, string $canonicalTemplate): string
    {
        $resolved = (string) preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            function (array $matches) use ($request): string {
                $param = $matches[1] ?? '';
                $value = $param !== '' ? $request->route($param) : null;

                return $value !== null ? rawurlencode((string) $value) : ($matches[0] ?? '');
            },
            $canonicalTemplate
        );

        if ($resolved === '') {
            return '/';
        }

        return str_starts_with($resolved, '/') ? $resolved : ('/' . ltrim($resolved, '/'));
    }

    private function canonicalUrl(Request $request, string $canonicalPath): string
    {
        if (
            str_starts_with($canonicalPath, 'http://')
            || str_starts_with($canonicalPath, 'https://')
        ) {
            return $canonicalPath;
        }

        return $request->getSchemeAndHttpHost() . $canonicalPath;
    }
}
