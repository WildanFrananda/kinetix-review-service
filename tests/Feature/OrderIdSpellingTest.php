<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Support\Facades\DB;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

const SPELLED_ORDER = "9a3e7c15-2b4d-4f68-8a1c-5d7e9f0b2c4a";

beforeEach(function () {
    $this->orders = new FakeOrderClient;
    $this->orders->addOrder(
        new OrderDetails(
            SPELLED_ORDER,
            "11111111-2222-3333-4444-555555555555",
            "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            true,
            ["TSHIRT-BLK-M"]
        )
    );
    $this->app->instance(OrderClientInterface::class, $this->orders);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
});

it("takes an order id in capitals as the same order, so a product is reviewed once", function () {
    $first = $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/products",
        ["order_id" => SPELLED_ORDER, "product_id" => "TSHIRT-BLK-M", "rating" => 5]
    );
    $first->assertStatus(201);

    $again = $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/products",
        ["order_id" => strtoupper(SPELLED_ORDER), "product_id" => "TSHIRT-BLK-M", "rating" => 1]
    );

    $again->assertStatus(409)->assertJson(["error" => "REQUEST_REFUSED", "review_id" => $first->json("id")]);
    $this->assertDatabaseCount("product_reviews", 1);
    $this->assertDatabaseCount("review_evidence", 1);
});

it("takes an order id in capitals as the same order, so a courier is rated once", function () {
    $first = $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/drivers",
        ["order_id" => SPELLED_ORDER, "rating" => 5]
    );
    $first->assertStatus(201);

    $again = $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/drivers",
        ["order_id" => strtoupper(SPELLED_ORDER), "rating" => 1]
    );

    $again->assertStatus(409)->assertJson(["error" => "REQUEST_REFUSED", "review_id" => $first->json("id")]);
    $this->assertDatabaseCount("driver_ratings", 1);
    $this->assertDatabaseCount("review_evidence", 1);
});

it("asks order, stores and logs the order id in its canonical form", function (
    string $path,
    array $body,
    string $table
) {
    $this->withHeaders(IdentityTokens::bearer())
        ->postJson($path, ["order_id" => strtoupper(SPELLED_ORDER), ...$body])
        ->assertStatus(201);

    expect($this->orders->askedFor())->toBe([SPELLED_ORDER])
        ->and(DB::table($table)->value("order_id"))->toBe(SPELLED_ORDER)
        ->and(DB::table("review_evidence")->value("order_id"))->toBe(SPELLED_ORDER);
})->with([
    "a product review" => [
        "/api/v1/reviews/products",
        ["product_id" => "TSHIRT-BLK-M", "rating" => 4],
        "product_reviews",
    ],
    "a driver rating" => ["/api/v1/reviews/drivers", ["rating" => 4], "driver_ratings"],
]);

it("refuses an order id that is not a UUID, before asking order", function (
    string $path,
    array $body,
    string $orderId
) {
    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-order-spelling"])
        ->postJson($path, ["order_id" => $orderId, ...$body]);

    $response->assertStatus(422)
        ->assertJson([
            "error" => "VALIDATION_FAILED",
            "errors" => ["order_id" => ["The order id field must be a valid UUID."]],
            "traceId" => "req-order-spelling",
        ]);
    expect($this->orders->askedFor())->toBe([]);
    $this->assertDatabaseCount("review_evidence", 0);
})->with([
    "a product review" => ["/api/v1/reviews/products", ["product_id" => "TSHIRT-BLK-M", "rating" => 5]],
    "a driver rating" => ["/api/v1/reviews/drivers", ["rating" => 5]],
])->with([
    "in braces" => ["{" . SPELLED_ORDER . "}"],
    "in parentheses" => ["(" . SPELLED_ORDER . ")"],
    "without hyphens" => [str_replace("-", "", SPELLED_ORDER)],
    "not an id at all" => ["ORD-2026-0001"],
]);
