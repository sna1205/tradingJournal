<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSelfRegistrationAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('auth.allow_self_register', true)) {
            abort(404);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

