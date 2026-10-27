<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

const NUL_ORDER = "2d6f8a0c-3e5b-4d79-9b1e-7c4a6e8f0a2b";

const NUL_REFUSED = "must not contain a NUL character (U+0000).";

beforeEach(function () {
    $orders = new FakeOrderClient;
    $orders->addOrder(
        new OrderDetails(
            NUL_ORDER,
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

it("refuses a text field carrying a NUL character, wherever it sits, and saves nothing", function (
    string $path,
    array $body,
    string $field,
    string $message
) {
    Log::spy();

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-nul"])->postJson($path, $body);

    $response->assertStatus(422)->assertExactJson([
        "error" => "VALIDATION_FAILED",
        "message" => $message,
        "errors" => [$field => [$message]],
        "traceId" => "req-nul",
    ]);
    $this->assertDatabaseCount("product_reviews", 0);
    $this->assertDatabaseCount("driver_ratings", 0);
    $this->assertDatabaseCount("review_evidence", 0);
    Log::shouldNotHaveReceived("error");
})->with([
    "a comment with one inside" => [
        "/api/v1/reviews/products",
        ["order_id" => NUL_ORDER, "product_id" => "TSHIRT-BLK-M", "rating" => 5, "comment" => "fits\u{0}well"],
        "comment",
        "The comment field " . NUL_REFUSED,
    ],
    "a comment that ends in one" => [
        "/api/v1/reviews/products",
        ["order_id" => NUL_ORDER, "product_id" => "TSHIRT-BLK-M", "rating" => 5, "comment" => "fits well\u{0}"],
        "comment",
        "The comment field " . NUL_REFUSED,
    ],
    "a comment that is only one" => [
        "/api/v1/reviews/products",
        ["order_id" => NUL_ORDER, "product_id" => "TSHIRT-BLK-M", "rating" => 5, "comment" => "\u{0}"],
        "comment",
        "The comment field " . NUL_REFUSED,
    ],
    "a product id that ends in one" => [
        "/api/v1/reviews/products",
        ["order_id" => NUL_ORDER, "product_id" => "TSHIRT-BLK-M\u{0}", "rating" => 5],
        "product_id",
        "The product id field " . NUL_REFUSED,
    ],
    "an order id that ends in one" => [
        "/api/v1/reviews/products",
        ["order_id" => NUL_ORDER . "\u{0}", "product_id" => "TSHIRT-BLK-M", "rating" => 5],
        "order_id",
        "The order id field must be a valid UUID.",
    ],
    "a courier's rating comment with one inside" => [
        "/api/v1/reviews/drivers",
        ["order_id" => NUL_ORDER, "rating" => 5, "comment" => "on\u{0}time"],
        "comment",
        "The comment field " . NUL_REFUSED,
    ],
]);

it("refuses a listing whose subject carries a NUL character, rather than listing another's", function (
    string $path,
    string $field
) {
    DB::table("product_reviews")->insert([
        "id" => "00000000-0000-4000-8000-000000000001",
        "order_id" => NUL_ORDER,
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "product_id" => "TSHIRT",
        "merchant_principal_id" => "3c9a77b1",
        "rating" => 5,
        "created_at" => now(),
        "updated_at" => now(),
    ]);

    $response = $this->withHeader("X-Request-Id", "req-nul-listing")->getJson($path);

    $message = "The " . str_replace("_", " ", $field) . " field " . NUL_REFUSED;
    $response->assertStatus(422)->assertExactJson([
        "error" => "VALIDATION_FAILED",
        "message" => $message,
        "errors" => [$field => [$message]],
        "traceId" => "req-nul-listing",
    ]);
})->with([
    "a product's reviews" => ["/api/v1/reviews/products/TSHIRT%00-BLK-M", "product_id"],
    "a merchant's reviews" => ["/api/v1/reviews/merchants/3c9a77b1%00-58de", "merchant_principal_id"],
    "a courier's ratings" => ["/api/v1/reviews/drivers/b7e2c05f%00-9a34", "driver_principal_id"],
]);
