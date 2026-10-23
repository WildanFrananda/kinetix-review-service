<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Support\Facades\Log;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $orders = new FakeOrderClient;
    $orders->addOrder(
        new OrderDetails(
            "ORD-VALIDATION-001",
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

it("answers 422 in the house shape, not 500, for an invalid product review", function (array $body, string $field) {
    Log::spy();

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-validation-1"])
        ->postJson("/api/v1/reviews/products", $body);

    $response->assertStatus(422)
        ->assertJson([
            "error" => "VALIDATION_FAILED",
            "traceId" => "req-validation-1",
        ])
        ->assertJsonStructure(["message", "errors" => [$field]]);

    $this->assertDatabaseCount("product_reviews", 0);
    $this->assertDatabaseCount("review_evidence", 0);
    Log::shouldNotHaveReceived("error");
})->with([
    "no rating" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M"],
        "rating",
    ],
    "a rating of six" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M", "rating" => 6],
        "rating",
    ],
    "a rating of zero" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M", "rating" => 0],
        "rating",
    ],
    "a rating that is not a number" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M", "rating" => "five"],
        "rating",
    ],
    "a rating of true, which a loose integer check reads as 1" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M", "rating" => true],
        "rating",
    ],
    "a rating sent as the string \"5\"" => [
        ["order_id" => "ORD-VALIDATION-001", "product_id" => "TSHIRT-BLK-M", "rating" => "5"],
        "rating",
    ],
    "a comment over 1000 characters" => [
        [
            "order_id" => "ORD-VALIDATION-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
            "comment" => str_repeat("a", 1001),
        ],
        "comment",
    ],
    "no order" => [
        ["product_id" => "TSHIRT-BLK-M", "rating" => 5],
        "order_id",
    ],
    "no product" => [
        ["order_id" => "ORD-VALIDATION-001", "rating" => 5],
        "product_id",
    ],
]);

it("answers 422 in the house shape, not 500, for an invalid driver rating", function (array $body, string $field) {
    Log::spy();

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-validation-2"])
        ->postJson("/api/v1/reviews/drivers", $body);

    $response->assertStatus(422)
        ->assertJson([
            "error" => "VALIDATION_FAILED",
            "traceId" => "req-validation-2",
        ])
        ->assertJsonStructure(["message", "errors" => [$field]]);

    $this->assertDatabaseCount("driver_ratings", 0);
    $this->assertDatabaseCount("review_evidence", 0);
    Log::shouldNotHaveReceived("error");
})->with([
    "no rating" => [
        ["order_id" => "ORD-VALIDATION-001"],
        "rating",
    ],
    "a rating of six" => [
        ["order_id" => "ORD-VALIDATION-001", "rating" => 6],
        "rating",
    ],
    "a rating of true, which a loose integer check reads as 1" => [
        ["order_id" => "ORD-VALIDATION-001", "rating" => true],
        "rating",
    ],
    "a rating sent as the string \"5\"" => [
        ["order_id" => "ORD-VALIDATION-001", "rating" => "5"],
        "rating",
    ],
    "a comment over 1000 characters" => [
        ["order_id" => "ORD-VALIDATION-001", "rating" => 5, "comment" => str_repeat("a", 1001)],
        "comment",
    ],
    "no order" => [
        ["rating" => 5],
        "order_id",
    ],
]);

it("answers 422, not 500, for a body that is not JSON at all", function () {
    $response = $this->call(
        "POST",
        "/api/v1/reviews/products",
        [],
        [],
        [],
        [
            "HTTP_AUTHORIZATION" => "Bearer " . IdentityTokens::token(),
            "CONTENT_TYPE" => "application/json",
            "HTTP_ACCEPT" => "application/json",
        ],
        "{\"order_id\": \"ORD-VALIDATION-001\", \"rating\": "
    );

    $response->assertStatus(422)->assertJson(["error" => "VALIDATION_FAILED"]);
    $this->assertDatabaseCount("product_reviews", 0);
});

it("answers 422 for a rating written as 4.0, which is a JSON number but not an integer", function (string $path) {
    $response = $this->call(
        "POST",
        $path,
        [],
        [],
        [],
        [
            "HTTP_AUTHORIZATION" => "Bearer " . IdentityTokens::token(),
            "CONTENT_TYPE" => "application/json",
            "HTTP_ACCEPT" => "application/json",
        ],
        "{\"order_id\": \"ORD-VALIDATION-001\", \"product_id\": \"TSHIRT-BLK-M\", \"rating\": 4.0}"
    );

    $response->assertStatus(422)
        ->assertJson(["error" => "VALIDATION_FAILED"])
        ->assertJsonStructure(["errors" => ["rating"]]);
    $this->assertDatabaseCount("review_evidence", 0);
})->with([
    "a product review" => ["/api/v1/reviews/products"],
    "a driver rating" => ["/api/v1/reviews/drivers"],
]);

it("still saves a review the rules accept, at the edges of what they allow", function () {
    $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-VALIDATION-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 1,
                "comment" => str_repeat("a", 1000),
            ]
        )
        ->assertStatus(201);

    $this->assertDatabaseCount("product_reviews", 1);
});
