<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $orders = new FakeOrderClient;
    $orders->addOrder(
        new OrderDetails(
            "ORD-OUTAGE-001",
            "11111111-2222-3333-4444-555555555555",
            "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            true,
            ["TSHIRT-BLK-M"]
        )
    );
    $this->app->instance(OrderClientInterface::class, $orders);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
});

afterEach(function () {
    config(["database.default" => "pgsql"]);
});

function databaseRefusingConnections(): void {
    config([
        "database.connections.refusing" => [
            ...config("database.connections.pgsql"),
            "host" => "127.0.0.1",
            "port" => "1",
        ],
        "database.default" => "refusing",
    ]);
}

function databaseUnavailableAnswer(string $traceId): array {
    return [
        "error" => "SERVICE_UNAVAILABLE",
        "message" => "the review database could not be reached, so this request could not be completed. "
            . "Try again later.",
        "traceId" => $traceId,
    ];
}

it("answers 503, not 500, and logs the cause once, when the database refuses connections", function (
    string $method,
    string $path,
    array $body
) {
    Log::spy();
    databaseRefusingConnections();

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-database-down"])
        ->json($method, $path, $body);

    $response->assertStatus(503)->assertExactJson(databaseUnavailableAnswer("req-database-down"));
    Log::shouldHaveReceived("warning")
        ->withArgs(function (string $message, array $context): bool {
            return str_starts_with($message, "review's database could not be reached")
                && str_starts_with($context["reason"], "PDOException: SQLSTATE[08006]")
                && $context["request_id"] === "req-database-down";
        })
        ->once();
    Log::shouldNotHaveReceived("error");
})->with([
    "a product review" => [
        "POST",
        "/api/v1/reviews/products",
        ["order_id" => "ORD-OUTAGE-001", "product_id" => "TSHIRT-BLK-M", "rating" => 5],
    ],
    "a driver rating" => ["POST", "/api/v1/reviews/drivers", ["order_id" => "ORD-OUTAGE-001", "rating" => 5]],
    "a product's reviews" => ["GET", "/api/v1/reviews/products/TSHIRT-BLK-M", []],
    "a merchant's reviews" => ["GET", "/api/v1/reviews/merchants/3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3", []],
    "a courier's ratings" => ["GET", "/api/v1/reviews/drivers/b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914", []],
]);

it("answers 503, not 500, when the database ends the session in the middle of a transaction", function () {
    config(["database.connections.ended" => config("database.connections.pgsql")]);
    Route::get("/api/v1/test-only/session-ended", function () {
        $ended = DB::connection("ended");
        $ended->beginTransaction();
        $session = $ended->selectOne("SELECT pg_backend_pid() AS pid");
        DB::selectOne("SELECT pg_terminate_backend(?, 5000) AS ended", [$session->pid]);

        return $ended->select("SELECT 1");
    });

    $response = $this->withHeader("X-Request-Id", "req-session-ended")->getJson("/api/v1/test-only/session-ended");

    $response->assertStatus(503)->assertExactJson(databaseUnavailableAnswer("req-session-ended"));
});

it("still answers 500, and logs it, for a query the database refuses on its merits", function () {
    Log::spy();
    Route::get("/api/v1/test-only/bad-query", fn () => DB::select("SELECT * FROM a_table_review_never_created"));

    $response = $this->withHeader("X-Request-Id", "req-bad-query")->getJson("/api/v1/test-only/bad-query");

    $response->assertStatus(500)->assertJson([
        "error" => "INTERNAL_ERROR",
        "traceId" => "req-bad-query",
    ]);
    Log::shouldHaveReceived("error")
        ->withArgs(function (string $message): bool {
            return $message === "unhandled exception serving GET api/v1/test-only/bad-query";
        })
        ->once();
});
