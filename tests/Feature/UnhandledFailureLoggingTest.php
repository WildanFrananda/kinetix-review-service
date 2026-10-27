<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $this->logFile = storage_path("framework/testing/log-" . bin2hex(random_bytes(8)) . ".jsonl");
    config([
        "logging.default" => "json",
        "logging.channels.json.handler_with.stream" => $this->logFile,
    ]);
});

afterEach(function () {
    if (is_file($this->logFile)) {
        unlink($this->logFile);
    }
});

function linesTheJsonChannelWrote(string $path): array {
    if (! is_file($path)) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    );
}

it("logs a 500 once, with the exception class, its SQLSTATE and its SQL, never the values bound to it", function () {
    $buyer = "11111111-2222-3333-4444-555555555555";
    $merchant = "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3";
    $comment = "the box came crushed, ring me on +62 812 5555 0199 before you send another";
    $orders = new FakeOrderClient;
    $orders->addOrder(
        new OrderDetails(
            "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0a",
            $buyer,
            $merchant,
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            true,
            ["MUG-WHT-L"]
        )
    );
    $this->app->instance(OrderClientInterface::class, $orders);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
    DB::statement("ALTER TABLE product_reviews ADD CONSTRAINT test_only_refuses_every_row CHECK (false)");

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-refused-row"])
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0a",
                "product_id" => "MUG-WHT-L",
                "rating" => 2,
                "comment" => $comment,
            ]
        );

    $response->assertStatus(500)->assertExactJson([
        "error" => "INTERNAL_ERROR",
        "message" => "something went wrong handling this request. No review was saved unless a previous response "
            . "said so.",
        "traceId" => "req-refused-row",
    ]);
    $lines = linesTheJsonChannelWrote($this->logFile);
    expect($lines)->toHaveCount(1)
        ->and(array_keys($lines[0]))->toBe([
            "timestamp",
            "level",
            "logger",
            "message",
            "request_id",
            "exception",
            "sqlstate",
            "sql",
        ])
        ->and($lines[0])->toMatchArray([
            "level" => "ERROR",
            "message" => "unhandled exception serving POST api/v1/reviews/products",
            "request_id" => "req-refused-row",
            "exception" => QueryException::class,
            "sqlstate" => "23514",
        ])
        ->and($lines[0]["sql"])->toStartWith("insert into \"product_reviews\"")->toContain("values (?");
    $written = (string) file_get_contents($this->logFile);
    foreach ([$comment, "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0a", "MUG-WHT-L", $buyer, $merchant] as $bound) {
        expect($written)->not->toContain($bound);
    }
});

it("logs a 500 from outside the database once, with the exception class and its message", function () {
    Route::get("/api/v1/test-only/unmapped-logged", fn () => throw new LogicException("a bug in review's own code"));

    $response = $this->withHeader("X-Request-Id", "req-unmapped-logged")->getJson("/api/v1/test-only/unmapped-logged");

    $response->assertStatus(500)->assertJson(["traceId" => "req-unmapped-logged"]);
    $lines = linesTheJsonChannelWrote($this->logFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray([
            "level" => "ERROR",
            "message" => "unhandled exception serving GET api/v1/test-only/unmapped-logged",
            "request_id" => "req-unmapped-logged",
            "exception" => LogicException::class,
            "reason" => "a bug in review's own code",
        ])
        ->and($lines[0])->not->toHaveKeys(["sqlstate", "sql"]);
});

it("leaves an exception reported outside an API request to Laravel's own reporter", function () {
    report(new RuntimeException("a console command failed"));

    $lines = linesTheJsonChannelWrote($this->logFile);
    expect($lines)->toHaveCount(1)
        ->and($lines[0]["message"])->toBe("a console command failed")
        ->and($lines[0]["exception"]["class"])->toBe(RuntimeException::class);
});
