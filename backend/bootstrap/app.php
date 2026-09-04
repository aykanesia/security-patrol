<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $unauthorized = fn () => response()->json([
            'success' => false,
            'message' => 'Unauthenticated. Token tidak valid atau tidak diberikan.',
            'error_code' => 'UNAUTHENTICATED',
            'data' => (object) [],
        ], 401);

        // API-only app: jangan redirect ke route 'login' — selalu balas JSON 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($unauthorized) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $unauthorized();
            }

            return null;
        });

        // Tanpa header Accept: application/json, middleware Auth mencoba redirect ke
        // route 'login' yang tidak ada (app API-only) → RouteNotFoundException 500.
        // Tangani agar jalur API tetap balas JSON 401, bukan 500.
        $exceptions->render(function (RouteNotFoundException $e, Request $request) use ($unauthorized) {
            if ($request->is('api/*')) {
                return $unauthorized();
            }

            return null;
        });
    })->create();
