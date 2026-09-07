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

function ratedOrder(
    string $id,
    string $principal = "11111111-2222-3333-4444-555555555555",
    string $status = "DELIVERED"
): array {
    return [
        "order_id" => $id,
        "order_number" => $id,
        "customer_principal_id" => $principal,
        "status" => $status,
        "items" => [],
    ];
}

it("refuses a request carrying no token", function () {
    $response = $this->postJson("/api/v1/reviews/drivers", [
        "order_id" => "ORD-TEST-001",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 5,
    ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a request carrying only an X-User-Id header", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-TEST-001",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            "rating" => 5,
        ]);

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("rejects a rating for an order that does not exist", function () {
    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "NON-EXISTENT-ORDER",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for somebody else's order", function () {
    $this->fakeOrderClient->addOrder("ORD-OTHER-001", ratedOrder("ORD-OTHER-001", "22222222-0000-0000-0000-000000000000"));

    $response = $this->withHeaders(IdentityTokens::bearer(["uid" => 1001]))
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-OTHER-001",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for an order that is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder("ORD-PENDING-001", ratedOrder("ORD-PENDING-001", "11111111-2222-3333-4444-555555555555", "PAID"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-PENDING-001",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            "rating" => 5,
        ]);

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("creates a rating, attributing it to the token's account", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", ratedOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-DELIVERED-001",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            "rating" => 5,
            "comment" => "Very polite driver!",
        ]);

    $response->assertStatus(201)->assertJson([
        "order_id" => "ORD-DELIVERED-001",
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 5,
        "comment" => "Very polite driver!",
    ]);
});

it("reads a driver's ratings without a token", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", ratedOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/drivers", [
        "order_id" => "ORD-DELIVERED-001",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 4,
    ]);

    $response = $this->getJson("/api/v1/reviews/drivers/b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914");

    $response->assertStatus(200);
});
