<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Illuminate\Support\Facades\DB;
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
    bool $delivered = true,
    string $driver = "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914"
): OrderDetails {
    return new OrderDetails(
        $id,
        $principal,
        "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        $driver,
        $delivered,
        []
    );
}

it("refuses a request carrying no token", function () {
    $response = $this->postJson(
        "/api/v1/reviews/drivers",
        [
            "order_id" => "ORD-TEST-001",
            "rating" => 5,
        ]
    );

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a request carrying only an X-User-Id header", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-TEST-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("rejects a rating for an order that does not exist", function () {
    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "NON-EXISTENT-ORDER",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for somebody else's order", function () {
    $this->fakeOrderClient->addOrder(
        ratedOrder("ORD-OTHER-001", "22222222-0000-0000-0000-000000000000")
    );

    $response = $this->withHeaders(IdentityTokens::bearer(["uid" => 1001]))
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-OTHER-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for an order that is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder(
        ratedOrder("ORD-PENDING-001", "11111111-2222-3333-4444-555555555555", false)
    );

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-PENDING-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a rating for an order that records no courier", function () {
    $this->fakeOrderClient->addOrder(
        ratedOrder("ORD-NOCOURIER-001", "11111111-2222-3333-4444-555555555555", true, "")
    );

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-NOCOURIER-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
    $this->assertDatabaseCount("driver_ratings", 0);
});

it("refuses a courier's rating of its own delivery", function () {
    $this->fakeOrderClient->addOrder(
        ratedOrder(
            "ORD-SELF-001",
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            true,
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914"
        )
    );

    $response = $this->withHeaders([
        ...IdentityTokens::bearer(["sub" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914", "role" => "driver"]),
        "X-Request-Id" => "req-self-rating",
    ])->postJson(
        "/api/v1/reviews/drivers",
        [
            "order_id" => "ORD-SELF-001",
            "rating" => 5,
        ]
    );

    $response->assertStatus(422)->assertJson([
        "error" => "UNPROCESSABLE_ENTITY",
        "message" => "A courier cannot rate its own delivery.",
        "traceId" => "req-self-rating",
    ]);
    $this->assertDatabaseCount("driver_ratings", 0);
    $this->assertDatabaseCount("review_evidence", 0);
});

it("rates the courier who delivered the order, attributed to the token's account", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-DELIVERED-001",
                "rating" => 5,
                "comment" => "Very polite driver!",
            ]
        );

    $response->assertStatus(201)->assertJson([
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 5,
        "comment" => "Very polite driver!",
    ]);

    $this->assertDatabaseHas(
        "driver_ratings",
        [
            "order_id" => "ORD-DELIVERED-001",
            "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        ]
    );
});

it("rates the order's courier, whatever courier the request names", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));

    $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-DELIVERED-001",
                "driver_principal_id" => "0c4d8e2f-6a1b-4f3e-9d7c-5b8a2e1f0d39",
                "rating" => 1,
            ]
        )
        ->assertStatus(201);

    $this->assertDatabaseHas(
        "driver_ratings",
        [
            "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        ]
    );
    $this->assertDatabaseMissing(
        "driver_ratings",
        [
            "driver_principal_id" => "0c4d8e2f-6a1b-4f3e-9d7c-5b8a2e1f0d39",
        ]
    );
});

it("refuses a second rating of the same order's courier", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));
    $rating = [
        "order_id" => "ORD-DELIVERED-001",
        "rating" => 5,
    ];

    $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", $rating)
        ->assertStatus(201);

    $response = $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/drivers", $rating);

    $response->assertStatus(409)->assertJson(["error" => "REQUEST_REFUSED"]);
    $this->assertDatabaseCount("driver_ratings", 1);
});

it("names the rating already given in the 409, so an author who lost the 201 can find it", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));
    $rating = [
        "order_id" => "ORD-DELIVERED-001",
        "rating" => 5,
    ];
    $created = $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/drivers", $rating);

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-rated-twice"])
        ->postJson("/api/v1/reviews/drivers", [...$rating, "rating" => 1]);

    $response->assertStatus(409)->assertExactJson([
        "error" => "REQUEST_REFUSED",
        "message" => "the courier of this order has already been rated. Nothing was saved.",
        "review_id" => $created->json("id"),
        "traceId" => "req-rated-twice",
    ]);
    $this->assertDatabaseHas("driver_ratings", ["id" => $created->json("id"), "rating" => 5]);
});

it("names no rating in the 409 when the rating already there is not the caller's", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));
    DB::table("driver_ratings")->insert([
        "id" => "7b2a1d3f-9c4e-4f6a-8b8c-2d3e4f5a6b7c",
        "order_id" => "ORD-DELIVERED-001",
        "customer_principal_id" => "22222222-0000-0000-0000-000000000000",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 3,
        "created_at" => now(),
        "updated_at" => now(),
    ]);

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-not-the-author"])
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-DELIVERED-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(409)->assertExactJson([
        "error" => "REQUEST_REFUSED",
        "message" => "the courier of this order has already been rated. Nothing was saved.",
        "review_id" => null,
        "traceId" => "req-not-the-author",
    ]);
});

it("answers 503 with a Retry-After when the breaker has stopped dialling order-service", function () {
    $this->fakeOrderClient->markUnreachable("ORD-DELIVERED-001", 30);

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/drivers",
            [
                "order_id" => "ORD-DELIVERED-001",
                "rating" => 5,
            ]
        );

    $response->assertStatus(503)
        ->assertHeader("Retry-After", "30")
        ->assertJson(["error" => "SERVICE_UNAVAILABLE"]);
});

it("reads a driver's ratings without a token, and without saying who gave them", function () {
    $this->fakeOrderClient->addOrder(ratedOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/drivers",
        [
            "order_id" => "ORD-DELIVERED-001",
            "rating" => 4,
        ]
    );

    $response = $this->getJson("/api/v1/reviews/drivers/b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914");

    $response->assertStatus(200)
        ->assertJson(["total_ratings" => 1])
        ->assertJsonPath("data.0.rating", 4)
        ->assertJsonMissingPath("data.0.customer_principal_id")
        ->assertJsonMissingPath("data.0.order_id");
});
