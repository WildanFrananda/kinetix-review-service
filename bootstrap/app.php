<?php

declare(strict_types=1);

use App\Http\Middleware\HttpMetrics;
use App\Http\Middleware\RequestId as RequestIdMiddleware;
use App\Observability\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . "/../routes/web.php",
        api: __DIR__ . "/../routes/api.php",
        commands: __DIR__ . "/../routes/console.php",
        health: "/up",
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([HttpMetrics::class, RequestIdMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is("api/*") || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is("api/*") && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return null;
            }

            if ($e instanceof AuthenticationException) {
                return new JsonResponse(
                    [
                        "error" => "UNAUTHORIZED",
                        "message" => $e->getMessage(),
                        "traceId" => RequestId::current() ?? "-",
                    ],
                    401
                );
            }

            if ($e instanceof ValidationException) {
                return new JsonResponse(
                    [
                        "error" => "VALIDATION_FAILED",
                        "message" => $e->getMessage(),
                        "errors" => $e->errors(),
                        "traceId" => RequestId::current() ?? "-",
                    ],
                    $e->status
                );
            }

            if ($e instanceof HttpExceptionInterface) {
                return new JsonResponse(
                    [
                        "error" => $e->getStatusCode() === 503 ? "SERVICE_UNAVAILABLE" : "REQUEST_REFUSED",
                        "message" => $e->getMessage(),
                        "traceId" => RequestId::current() ?? "-",
                    ],
                    $e->getStatusCode(),
                    $e->getHeaders()
                );
            }

            Log::error(
                "unhandled exception serving {$request->method()} {$request->path()}",
                [
                    "exception" => $e::class,
                    "message" => $e->getMessage(),
                    "request_id" => RequestId::current() ?? "-",
                ]
            );

            return new JsonResponse(
                [
                    "error" => "INTERNAL_ERROR",
                    "message" => "something went wrong handling this request. No review was saved "
                        . "unless a previous response said so.",
                    "traceId" => RequestId::current() ?? "-",
                ],
                500
            );
        });
    })->create();
