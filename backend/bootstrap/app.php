<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\ApiErrorResponder;
use App\Support\DatabaseExceptionInspector;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(static function (Request $request): ?string {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('sanctum/*')) {
                return null;
            }

            return '/';
        });
        $middleware->statefulApi();
        $middleware->alias([
            'idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
            'deprecated.alias' => \App\Http\Middleware\DeprecatedApiAliasMiddleware::class,
            'allow.register' => \App\Http\Middleware\EnsureSelfRegistrationAllowed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            $errors = $exception->errors();

            return ApiErrorResponder::respond(
                request: $request,
                status: 422,
                code: 'validation_failed',
                message: 'Validation failed.',
                details: ApiErrorResponder::flattenValidationErrors($errors),
                legacyErrors: $errors
            );
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            $retryAfter = (int) max(1, (int) $exception->getHeaders()['Retry-After'] ?? 1);

            return ApiErrorResponder::respond(
                request: $request,
                status: 429,
                code: 'rate_limited',
                message: 'Too many requests. Please retry later.',
                details: [[
                    'field' => 'request',
                    'message' => 'Rate limit exceeded for this endpoint.',
                ]],
                meta: ['retryAfterSeconds' => $retryAfter]
            )->header('Retry-After', (string) $retryAfter);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (
                !$request->expectsJson()
                && !$request->is('api/*')
                && !$request->is('sanctum/*')
            ) {
                return null;
            }

            return ApiErrorResponder::respond(
                request: $request,
                status: 401,
                code: 'unauthenticated',
                message: 'Authentication is required to access this resource.'
            );
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            return ApiErrorResponder::respond(
                request: $request,
                status: 403,
                code: 'forbidden',
                message: $exception->getMessage() !== '' ? $exception->getMessage() : 'You are not allowed to perform this action.'
            );
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            return ApiErrorResponder::respond(
                request: $request,
                status: 404,
                code: 'resource_not_found',
                message: 'Requested resource was not found.'
            );
        });

        $exceptions->render(function (QueryException $exception, Request $request) {
            if (
                !$request->expectsJson()
                && !$request->is('api/*')
                && !$request->is('sanctum/*')
            ) {
                return null;
            }

            if (!DatabaseExceptionInspector::isConnectionIssue($exception)) {
                return null;
            }

            return ApiErrorResponder::respond(
                request: $request,
                status: 503,
                code: 'database_unavailable',
                message: 'Database is temporarily unavailable. Verify DB_HOST/DB_PORT and ensure MySQL is running, or set DB_CONNECTION=sqlite for local development.'
            );
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            $status = $exception->getStatusCode();
            if ($status < 400) {
                return null;
            }

            return ApiErrorResponder::respond(
                request: $request,
                status: $status,
                code: 'http_error',
                message: $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.'
            );
        });
    })->create();
