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

it("returns unauthorized when X-User-Id header is missing on driver rating creation", function () {
    $response = $this->postJson("/api/v1/reviews/drivers", [
        "order_id" => "ORD-TEST-001",
        "driver_id" => 99,
        "rating" => 5,
    ]);

    $response->assertStatus(401)
        ->assertJson(["error" => "UNAUTHORIZED"]);
});

it("rejects driver rating if customer identity is not found in Identity Service", function () {
    $response = $this->withHeader("X-User-Id", "99999")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-DELIVERED-001",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects driver rating if order is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder("ORD-PENDING-001", [
        "id" => "ORD-PENDING-001",
        "customerId" => 1001,
        "status" => "PAID",
    ]);

    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-PENDING-001",
            "driver_id" => 99,
            "rating" => 5,
        ]);

    $response->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("creates driver rating successfully when identity is valid and order is DELIVERED", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", [
        "id" => "ORD-DELIVERED-001",
        "customerId" => 1001,
        "status" => "DELIVERED",
    ]);

    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-DELIVERED-001",
            "driver_id" => 99,
            "rating" => 5,
            "comment" => "Very polite driver!",
        ]);

    $response->assertStatus(201)
        ->assertJson([
            "order_id" => "ORD-DELIVERED-001",
            "customer_id" => 1001,
            "driver_id" => 99,
            "rating" => 5,
            "comment" => "Very polite driver!",
        ]);
});

it("calculates correct average rating for a driver", function () {
    $this->fakeOrderClient->addOrder("ORD-DELIVERED-001", [
        "id" => "ORD-DELIVERED-001",
        "customerId" => 1001,
        "status" => "DELIVERED",
    ]);

    $this->withHeader("X-User-Id", "1001")
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "ORD-DELIVERED-001",
            "driver_id" => 88,
            "rating" => 4,
        ]);

    $response = $this->getJson("/api/v1/reviews/drivers/88");

    $response->assertStatus(200)
        ->assertJson([
            "driver_id" => 88,
            "average_rating" => 4.0,
            "total_ratings" => 1,
        ]);
});
