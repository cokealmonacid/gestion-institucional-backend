<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Responses\ApiResponse;
use App\Platform\Http\Middleware\PlatformAuthenticate;
use App\Platform\Http\Middleware\PlatformAuthorize;
use App\Platform\Http\Middleware\RejectPlatformCredentials;
use App\Platform\PlatformException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Institution\Exceptions\InstitutionAccessException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'platform.auth' => PlatformAuthenticate::class,
            'platform.can' => PlatformAuthorize::class,
        ]);
        $middleware->api(prepend: [RejectPlatformCredentials::class]);
        $middleware->web(prepend: [RejectPlatformCredentials::class], append: [EnsureUserIsActive::class]);
        $middleware->appendToPriorityList(Authenticate::class, EnsureUserIsActive::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(PlatformException::class);
        $exceptions->dontReport(InstitutionAccessException::class);
        $exceptions->render(function (InstitutionAccessException $e, Request $request) {
            $response = ApiResponse::error($e->errorCode, 'Institutional access is not available for this operation.', $e->status);
            if ($e->details !== []) {
                $data = $response->getData(true);
                $data['error']['details'] = $e->details;
                $response->setData($data);
            }

            return $response;
        });
        $exceptions->render(function (PlatformException $e, Request $request) {
            return ApiResponse::error($e->errorCode, 'The platform request could not be completed.', $e->status);
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/v1/institution/users')) {
                return response()->json(['success' => false, 'message' => 'Validation failed.', 'data' => ['error' => $e->errors()]], 422);
            }
            if ($request->is('api/v1/platform/*')) {
                return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $e->errors());
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/v1/platform/*')) {
                return ApiResponse::error('RESOURCE_NOT_FOUND', 'The resource is unavailable.', 404);
            }
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/v1/platform/*')) {
                return ApiResponse::error('AUTH_RATE_LIMITED', 'Too many requests. Try again later.', 429)->withHeaders($e->getHeaders());
            }
            if ($request->is('api/v1/auth/*') || $request->is('api/v1/user/update-password')) {
                return ApiResponse::error('AUTH_RATE_LIMITED', 'Too many requests. Try again later.', 429);
            }
        });

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
                $request->isMethod('PATCH')
                && $request->is('api/v1/user/update-password')
            ) || (
                $request->isMethod('POST')
                && $request->is('api/v1/institution/tree-directory')
            ) || (
                $request->isMethod('POST')
                && $request->is('api/v1/institution/tree-directory/*/documents')
            ) || (
                $request->isMethod('GET')
                && preg_match('#^api/v1/documents/[^/]+(?:/download|/history|/versions|/versions/[^/]+/download)?$#', $request->path())
            ) || (
                $request->isMethod('POST')
                && preg_match('#^api/v1/documents/[^/]+/versions$#', $request->path())
            ) || (
                $request->isMethod('PATCH')
                && preg_match('#^api/v1/documents/[^/]+/versions/[^/]+/current$#', $request->path())
            ) || (
                in_array($request->method(), ['GET', 'PATCH'], true)
                && preg_match('#^api/v1/documents/[^/]+/versions(?:/notes|/[^/]+/note(?:/history)?)$#', $request->path())
            ) || (
                in_array($request->method(), ['GET', 'PATCH'], true)
                && preg_match('#^api/v1/documents/[^/]+/(?:responsible-options|responsible)$#', $request->path())
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

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/v1/platform/*')) {
                return ApiResponse::error('PLATFORM_ACCESS_DENIED', 'Platform permission is required.', 403);
            }
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/v1/platform/*') && ! $e instanceof HttpExceptionInterface) {
                return ApiResponse::error('PLATFORM_MUTATION_FAILED', 'The platform request could not be completed.', 500);
            }
        });
    })->create();
