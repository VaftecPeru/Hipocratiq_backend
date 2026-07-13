<?php

use App\Http\Middleware\EnsureAuthenticationSessionIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'auth.session' => EnsureAuthenticationSessionIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => true,
        );
        $exceptions->render(fn (AuthenticationException $exception): JsonResponse => response()->json([
            'success' => false,
            'code' => 'UNAUTHENTICATED',
            'message' => 'No autenticado.',
        ], 401));
        $exceptions->render(function (HttpException $exception): ?JsonResponse {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            return response()->json([
                'success' => false,
                'code' => 'CSRF_TOKEN_MISMATCH',
                'message' => 'El token CSRF no es válido.',
            ], 419);
        });
    })->create();
