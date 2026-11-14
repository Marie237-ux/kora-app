<?php

use App\Http\Middleware\ApiContext;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RouteThrottle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(ApiContext::class);
        $middleware->alias(['permission' => RequirePermission::class, 'throttle' => RouteThrottle::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([UniqueConstraintViolationException::class]);
        $exceptions->report(function (QueryException $error) {
            $state = (string) ($error->errorInfo[0] ?? '');
            Log::error('Database operation failed', [
                'request_id' => request()->attributes->get('request_id'),
                'sql_state' => preg_match('/^[A-Z0-9]{5}$/', $state) ? $state : null,
            ]);

            return false;
        });
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $error, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthenticationException => 401,
                $error instanceof AuthorizationException => 403,
                $error instanceof UniqueConstraintViolationException => 409,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'VERSION_CONFLICT',
                419 => 'CSRF_EXPIRED',
                422 => 'VALIDATION_FAILED',
                429 => 'RATE_LIMITED',
                default => 'REQUEST_FAILED',
            };

            return response()->json(
                [
                    'error' => [
                        'code' => $code,
                        'message' => __('api.'.$code),
                        'fields' => $error instanceof ValidationException ? $error->errors() : (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                ],
                $status,
            );
        });
    })
    ->create();
