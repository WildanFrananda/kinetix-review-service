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

function ratedOrder(string $id, int $customerId = 1001, string $status = "DELIVERED"): array {
    return [
        "order_id" => $id,
        "order_number" => $id,
        "customer_id" => $customerId,
        "status" => $status,
        "items" => [],
    ];
}

it("refuses a request carrying no token", function () {
    $response = $this->postJson("/api/v1/reviews/drivers", [
        "order_id" => "ORD-TEST-001",
        "driver_id" => 99,
        "rating" => 5,
    ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a request carrying only an X-User-Id header", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-TEST-001",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("rejects a rating for an order that does not exist", function () {
    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "NON-EXISTENT-ORDER",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for somebody else's order", function () {
    $this->fakeOrderClient->addOrder("ORD-OTHER-001", ratedOrder("ORD-OTHER-001", 2002));

    $response = $this->withHeaders(IdentityTokens::bearer(["uid" => 1001]))
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-OTHER-001",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for an order that is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder("ORD-PENDING-001", ratedOrder("ORD-PENDING-001", 1001, "PAID"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-PENDING-001",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("creates a rating, attributing it to the token's account", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", ratedOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-DELIVERED-001",
            "driver_id" => 99,
            "rating" => 5,
            "comment" => "Very polite driver!",
        ]);

    $response->assertStatus(201)->assertJson([
        "order_id" => "ORD-DELIVERED-001",
        "customer_id" => 1001,
        "driver_id" => 99,
        "rating" => 5,
        "comment" => "Very polite driver!",
    ]);
});

it("reads a driver's ratings without a token", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", ratedOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/drivers", [
        "order_id" => "ORD-DELIVERED-001",
        "driver_id" => 99,
        "rating" => 4,
    ]);

    $response = $this->getJson("/api/v1/reviews/drivers/99");

    $response->assertStatus(200);
});
