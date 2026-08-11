<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            $usesAuthenticationContract = (
                $request->isMethod('GET')
                && $request->is('api/v1/user/profile')
            ) || (
                $request->isMethod('POST')
                && $request->is('api/v1/auth/logout')
            ) || (
                $request->isMethod('POST')
                && $request->is('api/v1/institution/tree-directory')
            );

            if ($usesAuthenticationContract) {
                return ApiResponse::error(
                    'AUTH_UNAUTHENTICATED',
                    'Authentication is required.',
                    401,
                );
            }

            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
