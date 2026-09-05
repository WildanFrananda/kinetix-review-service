<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $this->fakeOrderClient = new FakeOrderClient;
    $this->app->instance(OrderClientInterface::class, $this->fakeOrderClient);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
});

function deliveredOrder(string $id, int $customerId = 1001, string $status = "DELIVERED"): array {
    return [
        "order_id" => $id,
        "order_number" => $id,
        "customer_id" => $customerId,
        "status" => $status,
        "items" => [["product_id" => "TSHIRT-BLK-M", "product_title" => "Kaos", "quantity" => 1]],
    ];
}

it("refuses a request carrying no token", function () {
    $response = $this->postJson("/api/v1/reviews/products", [
        "order_id" => "ORD-TEST-001",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 5,
    ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a request carrying only an X-User-Id header", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a token signed by a key identity did not publish", function () {
    $forged = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);
    $token = Firebase\JWT\JWT::encode([
        "sub" => "11111111-2222-3333-4444-555555555555",
        "uid" => 1001,
        "email" => "customer@kinetix.test",
        "role" => "customer",
        "token_use" => "access",
        "iss" => env("JWT_ISSUER"),
        "aud" => env("JWT_AUDIENCE"),
        "exp" => time() + 900,
    ], $forged, "RS256", IdentityTokens::kid());

    $response = $this->withHeaders(["Authorization" => "Bearer {$token}"])
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(401);
});

it("refuses a refresh token, which is long-lived by design", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["token_use" => "refresh"]))
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(401);
});

it("refuses an expired token", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["exp" => time() - 1]))
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(401);
});

it("refuses a token minted for another issuer", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["iss" => "https://somewhere-else.example"]))
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(401);
});

it("rejects a review for an order that does not exist", function () {
    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "NON-EXISTENT-ORDER",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a review for somebody else's order", function () {
    $this->fakeOrderClient->addOrder("ORD-OTHER-001", deliveredOrder("ORD-OTHER-001", 2002));

    $response = $this->withHeaders(IdentityTokens::bearer(["uid" => 1001]))
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-OTHER-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a review for an order that is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder("ORD-PENDING-001", deliveredOrder("ORD-PENDING-001", 1001, "PAID"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-PENDING-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a review for a product the order does not contain", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", deliveredOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "SOMETHING-NEVER-BOUGHT",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("creates a review, attributing it to the token's account", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", deliveredOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "TSHIRT-BLK-M",
            "merchant_id" => 50,
            "rating" => 5,
            "comment" => "Awesome product!",
        ]);

    $response->assertStatus(201)->assertJson([
        "order_id" => "ORD-DELIVERED-001",
        "customer_id" => 1001,
        "product_id" => "TSHIRT-BLK-M",
        "merchant_id" => 50,
        "rating" => 5,
        "comment" => "Awesome product!",
    ]);
});

it("reads a product's reviews without a token, which is what a shopper does", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", deliveredOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/products", [
        "order_id" => "ORD-DELIVERED-001",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 4,
    ]);

    $response = $this->getJson("/api/v1/reviews/products/TSHIRT-BLK-M");

    $response->assertStatus(200)->assertJson(["product_id" => "TSHIRT-BLK-M", "total_reviews" => 1]);
});
