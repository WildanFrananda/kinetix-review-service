<?php

declare(strict_types=1);

use App\Contracts\Clients\IdentityClientInterface;
use App\Contracts\Clients\OrderClientInterface;
use Tests\FakeIdentityClient;
use Tests\FakeOrderClient;

beforeEach(function () {
    $this->fakeOrderClient = new FakeOrderClient;
    $this->fakeIdentityClient = new FakeIdentityClient;

    $this->app->instance(OrderClientInterface::class, $this->fakeOrderClient);
    $this->app->instance(IdentityClientInterface::class, $this->fakeIdentityClient);
});

it("returns unauthorized when X-User-Id header is missing on product review creation", function () {
    $response = $this->postJson("/api/v1/reviews/products", [
        "order_id" => "ORD-TEST-001",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 5,
        "comment" => "Great quality!",
    ]);

    $response->assertStatus(401)
        ->assertJson(["error" => "UNAUTHORIZED"]);
});

it("rejects review if customer identity is not found in Identity Service", function () {
    $response = $this->withHeader("X-User-Id", "99999")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects review if order does not exist or does not belong to customer", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "NON-EXISTENT-ORDER",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects review if order is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder("ORD-PENDING-001", [
        "id" => "ORD-PENDING-001",
        "customerId" => 1001,
        "status" => "PAID",
        "items" => [
            ["productId" => "TSHIRT-BLK-M"],
        ],
    ]);

    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-PENDING-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]);

    $response->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("creates product review successfully when identity is valid and order is DELIVERED", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", [
        "id" => "ORD-DELIVERED-001",
        "customerId" => 1001,
        "status" => "DELIVERED",
        "items" => [
            ["productId" => "TSHIRT-BLK-M"],
        ],
    ]);

    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "TSHIRT-BLK-M",
            "merchant_id" => 50,
            "rating" => 5,
            "comment" => "Awesome product!",
        ]);

    $response->assertStatus(201)
        ->assertJson([
            "order_id" => "ORD-DELIVERED-001",
            "customer_id" => 1001,
            "product_id" => "TSHIRT-BLK-M",
            "merchant_id" => 50,
            "rating" => 5,
            "comment" => "Awesome product!",
        ]);
});

it("calculates correct average rating for a product", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", [
        "id" => "ORD-DELIVERED-001",
        "customerId" => 1001,
        "status" => "DELIVERED",
        "items" => [["productId" => "SHOES-RUN-42"]],
    ]);

    $this->fakeOrderClient->addOrder("ORD-DELIVERED-002", [
        "id" => "ORD-DELIVERED-002",
        "customerId" => 1002,
        "status" => "COMPLETED",
        "items" => [["productId" => "SHOES-RUN-42"]],
    ]);

    $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "SHOES-RUN-42",
            "rating" => 5,
        ]);

    $this->withHeader("X-User-Id", "1002")
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "ORD-DELIVERED-002",
            "product_id" => "SHOES-RUN-42",
            "rating" => 3,
        ]);

    $response = $this->getJson("/api/v1/reviews/products/SHOES-RUN-42");

    $response->assertStatus(200)
        ->assertJson([
            "product_id" => "SHOES-RUN-42",
            "average_rating" => 4.0,
            "total_reviews" => 2,
        ]);
});
