<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

it("answers 401 in the house shape, not 500, for an AuthenticationException", function () {
    Log::spy();
    Route::get("/api/v1/test-only/unauthenticated", fn () => throw new AuthenticationException);

    $response = $this->withHeader("X-Request-Id", "req-authentication")->getJson("/api/v1/test-only/unauthenticated");

    $response->assertStatus(401)->assertExactJson([
        "error" => "UNAUTHORIZED",
        "message" => "Unauthenticated.",
        "traceId" => "req-authentication",
    ]);
    Log::shouldNotHaveReceived("error");
});

it("sends the response a throttle's HttpResponseException carries, with its status and headers, not 500", function () {
    Log::spy();
    RateLimiter::for("test-only", function (): Limit {
        return Limit::perMinute(1)->response(function (Request $request, array $headers): JsonResponse {
            return new JsonResponse(
                [
                    "error" => "REQUEST_REFUSED",
                    "message" => "slow down",
                    "traceId" => "req-throttled",
                ],
                429,
                $headers
            );
        });
    });
    Route::get("/api/v1/test-only/throttled", fn () => ["ok" => true])->middleware("throttle:test-only");

    $this->getJson("/api/v1/test-only/throttled")->assertStatus(200);

    $response = $this->withHeader("X-Request-Id", "req-throttled")->getJson("/api/v1/test-only/throttled");

    $response->assertStatus(429)
        ->assertHeader("Retry-After")
        ->assertExactJson([
            "error" => "REQUEST_REFUSED",
            "message" => "slow down",
            "traceId" => "req-throttled",
        ]);
    Log::shouldNotHaveReceived("error");
});

it("answers what Laravel maps a framework exception to, in the house shape", function (Throwable $thrown, int $status) {
    Route::get("/api/v1/test-only/framework", fn () => throw $thrown);

    $response = $this->withHeader("X-Request-Id", "req-framework")->getJson("/api/v1/test-only/framework");

    $response->assertStatus($status)
        ->assertJson([
            "error" => "REQUEST_REFUSED",
            "traceId" => "req-framework",
        ])
        ->assertJsonStructure(["message"]);
})->with([
    "a model that is not there" => fn () => [new ModelNotFoundException, 404],
    "an action not allowed" => fn () => [new AuthorizationException, 403],
    "a stale CSRF token" => fn () => [new TokenMismatchException, 419],
    "a request Symfony calls suspicious" => fn () => [new SuspiciousOperationException("untrusted host"), 400],
]);

it("still answers 500, and logs it, for an exception nothing maps", function () {
    Log::spy();
    Route::get("/api/v1/test-only/unmapped", fn () => throw new LogicException("a bug"));

    $response = $this->withHeader("X-Request-Id", "req-unmapped")->getJson("/api/v1/test-only/unmapped");

    $response->assertStatus(500)->assertJson([
        "error" => "INTERNAL_ERROR",
        "traceId" => "req-unmapped",
    ]);
    Log::shouldHaveReceived("error")
        ->withArgs(function (string $message, array $context): bool {
            return $message === "unhandled exception serving GET api/v1/test-only/unmapped"
                && $context["exception"] === LogicException::class;
        })
        ->once();
});
