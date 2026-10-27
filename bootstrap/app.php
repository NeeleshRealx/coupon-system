<?php

use App\Exceptions\Coupon\CouponException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stateless token API: never try to redirect unauthenticated API calls to
        // a (non-existent) "login" page — let them fall through to a JSON 401.
        $middleware->redirectGuestsTo(
            static fn (Request $request): ?string => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always answer API routes with JSON (401/403/404/422 included), even if
        // the client forgot the "Accept: application/json" header. Without this,
        // an unauthenticated API call would try to redirect to a "login" route.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // Business-rule failures are expected outcomes, not errors worth alerting on.
        $exceptions->dontReport(CouponException::class);

        // Lock contention that survived the transaction retries (MySQL 1205 lock
        // wait timeout / 1213 deadlock): tell the client to retry instead of
        // returning an opaque 500. SQL details are never exposed.
        $exceptions->render(static function (QueryException $e, Request $request): ?JsonResponse {
            $driverCode = $e->errorInfo[1] ?? null;

            if (! $request->is('api/*') || ! in_array($driverCode, [1205, 1213], true)) {
                return null; // fall through to the default handler
            }

            return new JsonResponse(
                ['error' => ['code' => 'service_busy', 'message' => 'The server is busy. Please retry.']],
                503,
                ['Retry-After' => '1'],
            );
        });
    })->create();
