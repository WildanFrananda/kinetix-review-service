<?php

declare(strict_types=1);

use App\Http\Middleware\HttpMetrics;
use App\Http\Middleware\RequestId as RequestIdMiddleware;
use App\Logging\FailureLogContext;
use App\Observability\RequestId;
use App\Repositories\AlreadyReviewedException;
use App\Resilience\DatabaseOutage;
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
        $answersJson = static fn (Request $request): bool => $request->is("api/*") || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($answersJson);

        $exceptions->dontReportWhen(fn (Throwable $e): bool => (new DatabaseOutage)->causedBy($e));

        $exceptions->report(function (Throwable $e) use ($answersJson): bool {
            $request = app()->bound("request") ? app("request") : null;
            if (! $request instanceof Request || ! $answersJson($request)) {
                return true;
            }

            Log::error(
                "unhandled exception serving {$request->method()} {$request->path()}",
                [
                    ...FailureLogContext::of($e),
                    "request_id" => RequestId::current() ?? "-",
                ]
            );

            return false;
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($answersJson): ?JsonResponse {
            if (! $answersJson($request)) {
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

            if ($e instanceof AlreadyReviewedException) {
                return new JsonResponse(
                    [
                        "error" => "REQUEST_REFUSED",
                        "message" => $e->getMessage(),
                        "review_id" => $e->reviewId,
                        "traceId" => RequestId::current() ?? "-",
                    ],
                    409
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

            if ((new DatabaseOutage)->causedBy($e)) {
                Log::warning(
                    "review's database could not be reached serving {$request->method()} {$request->path()}",
                    [
                        "reason" => DatabaseOutage::reason($e),
                        "request_id" => RequestId::current() ?? "-",
                    ]
                );

                return new JsonResponse(
                    [
                        "error" => "SERVICE_UNAVAILABLE",
                        "message" => "the review database could not be reached, so this request could not be "
                            . "completed. Try again later.",
                        "traceId" => RequestId::current() ?? "-",
                    ],
                    503
                );
            }

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
