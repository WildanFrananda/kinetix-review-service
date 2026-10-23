<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $this->fakeOrderClient = new FakeOrderClient;
    $this->app->instance(OrderClientInterface::class, $this->fakeOrderClient);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
});

function deliveredOrder(
    string $id,
    string $principal = "11111111-2222-3333-4444-555555555555",
    bool $delivered = true
): OrderDetails {
    return new OrderDetails(
        $id,
        $principal,
        "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        $delivered,
        ["TSHIRT-BLK-M"]
    );
}

it("refuses a request carrying no token", function () {
    $response = $this->postJson(
        "/api/v1/reviews/products",
        [
            "order_id" => "ORD-TEST-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]
    );

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("answers 401 in the house shape, with the request's trace id", function (?string $tokenUse) {
    $token = $tokenUse === null ? [] : IdentityTokens::bearer(["token_use" => $tokenUse]);

    $response = $this->withHeaders([...$token, "X-Request-Id" => "req-unauthorized"])
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401)
        ->assertJson([
            "error" => "UNAUTHORIZED",
            "traceId" => "req-unauthorized",
        ])
        ->assertJsonStructure(["message"]);
})->with([
    "no token" => [null],
    "a refresh token" => ["refresh"],
]);

it("refuses a request carrying only an X-User-Id header", function () {
    $response = $this->withHeader("X-User-Id", "1001")
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401)->assertJson(["error" => "UNAUTHORIZED"]);
});

it("refuses a token signed by a key identity did not publish", function () {
    $forged = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);
    $token = JWT::encode(
        [
            "sub" => "11111111-2222-3333-4444-555555555555",
            "uid" => 1001,
            "email" => "customer@kinetix.test",
            "role" => "customer",
            "token_use" => "access",
            "iss" => env("JWT_ISSUER"),
            "aud" => env("JWT_AUDIENCE"),
            "exp" => time() + 900,
        ],
        $forged,
        "RS256",
        IdentityTokens::kid()
    );

    $response = $this->withHeaders(["Authorization" => "Bearer {$token}"])
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401);
});

it("refuses a refresh token, which is long-lived by design", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["token_use" => "refresh"]))
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401);
});

it("refuses an expired token", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["exp" => time() - 1]))
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401);
});

it("refuses a token minted for another issuer", function () {
    $response = $this->withHeaders(IdentityTokens::bearer(["iss" => "https://somewhere-else.example"]))
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-TEST-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(401);
});

it("rejects a review for an order that does not exist", function () {
    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "NON-EXISTENT-ORDER",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("answers 503, not a 422 saying the order is missing, when order-service is unreachable", function () {
    $this->fakeOrderClient->markUnreachable("ORD-DELIVERED-001");

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-DELIVERED-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(503)->assertJson(["error" => "SERVICE_UNAVAILABLE"]);
});

it("rejects a review for somebody else's order", function () {
    $this->fakeOrderClient->addOrder(
        deliveredOrder("ORD-OTHER-001", "22222222-0000-0000-0000-000000000000")
    );

    $response = $this->withHeaders(IdentityTokens::bearer(["uid" => 1001]))
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-OTHER-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a review for an order that is not DELIVERED or COMPLETED", function () {
    $this->fakeOrderClient->addOrder(
        deliveredOrder("ORD-PENDING-001", "11111111-2222-3333-4444-555555555555", false)
    );

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-PENDING-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("rejects a review for a product the order does not contain", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-DELIVERED-001",
                "product_id" => "SOMETHING-NEVER-BOUGHT",
                "rating" => 5,
            ]
        );

    $response->assertStatus(422)->assertJson(["error" => "UNPROCESSABLE_ENTITY"]);
});

it("refuses a merchant's review of its own product, whatever the order says it bought", function () {
    $this->fakeOrderClient->addOrder(
        new OrderDetails(
            "ORD-SELF-001",
            "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
            "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
            "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
            true,
            ["TSHIRT-BLK-M"]
        )
    );

    $response = $this->withHeaders([
        ...IdentityTokens::bearer(["sub" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3", "role" => "merchant"]),
        "X-Request-Id" => "req-self-review",
    ])->postJson(
        "/api/v1/reviews/products",
        [
            "order_id" => "ORD-SELF-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ]
    );

    $response->assertStatus(422)->assertJson([
        "error" => "UNPROCESSABLE_ENTITY",
        "message" => "A merchant cannot review its own product.",
        "traceId" => "req-self-review",
    ]);
    $this->assertDatabaseCount("product_reviews", 0);
    $this->assertDatabaseCount("review_evidence", 0);
});

it("creates a review for the order's merchant, attributed to the token's account", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));

    $response = $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-DELIVERED-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
                "comment" => "Awesome product!",
            ]
        );

    $response->assertStatus(201)->assertJson([
        "product_id" => "TSHIRT-BLK-M",
        "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        "rating" => 5,
        "comment" => "Awesome product!",
    ]);

    $this->assertDatabaseHas(
        "product_reviews",
        [
            "order_id" => "ORD-DELIVERED-001",
            "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
            "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        ]
    );
});

it("files the review under the order's merchant, whatever merchant the request names", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));

    $this->withHeaders(IdentityTokens::bearer())
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-DELIVERED-001",
                "product_id" => "TSHIRT-BLK-M",
                "merchant_principal_id" => "9d0e5f61-3b2a-4c7d-8e9f-a1b2c3d4e5f6",
                "rating" => 1,
            ]
        )
        ->assertStatus(201);

    $this->assertDatabaseHas(
        "product_reviews",
        [
            "order_id" => "ORD-DELIVERED-001",
            "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        ]
    );
    $this->assertDatabaseMissing(
        "product_reviews",
        [
            "merchant_principal_id" => "9d0e5f61-3b2a-4c7d-8e9f-a1b2c3d4e5f6",
        ]
    );
});

it("refuses a second review of the same product from the same order", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));
    $review = [
        "order_id" => "ORD-DELIVERED-001",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 5,
    ];

    $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", $review)
        ->assertStatus(201);

    $response = $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/products", $review);

    $response->assertStatus(409)->assertJson(["error" => "REQUEST_REFUSED"]);
    $this->assertDatabaseCount("product_reviews", 1);
});

it("names the review already written in the 409, so an author who lost the 201 can find it", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));
    $review = [
        "order_id" => "ORD-DELIVERED-001",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 5,
    ];
    $created = $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/products", $review);

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-reviewed-twice"])
        ->postJson("/api/v1/reviews/products", [...$review, "rating" => 1]);

    $response->assertStatus(409)->assertExactJson([
        "error" => "REQUEST_REFUSED",
        "message" => "this product from this order has already been reviewed. Nothing was saved.",
        "review_id" => $created->json("id"),
        "traceId" => "req-reviewed-twice",
    ]);
    $this->assertDatabaseHas("product_reviews", ["id" => $created->json("id"), "rating" => 5]);
});

it("names no review in the 409 when the review already there is not the caller's", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));
    DB::table("product_reviews")->insert([
        "id" => "6a1f0c2e-8b3d-4e5f-9a7b-1c2d3e4f5a6b",
        "order_id" => "ORD-DELIVERED-001",
        "customer_principal_id" => "22222222-0000-0000-0000-000000000000",
        "product_id" => "TSHIRT-BLK-M",
        "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        "rating" => 3,
        "created_at" => now(),
        "updated_at" => now(),
    ]);

    $response = $this->withHeaders([...IdentityTokens::bearer(), "X-Request-Id" => "req-not-the-author"])
        ->postJson(
            "/api/v1/reviews/products",
            [
                "order_id" => "ORD-DELIVERED-001",
                "product_id" => "TSHIRT-BLK-M",
                "rating" => 5,
            ]
        );

    $response->assertStatus(409)->assertExactJson([
        "error" => "REQUEST_REFUSED",
        "message" => "this product from this order has already been reviewed. Nothing was saved.",
        "review_id" => null,
        "traceId" => "req-not-the-author",
    ]);
});

it("reads a product's reviews without a token, and without saying who wrote them", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/products",
        [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 4,
        ]
    );

    $response = $this->getJson("/api/v1/reviews/products/TSHIRT-BLK-M");

    $response->assertStatus(200)
        ->assertJson(["product_id" => "TSHIRT-BLK-M", "total_reviews" => 1])
        ->assertJsonPath("data.0.rating", 4)
        ->assertJsonMissingPath("data.0.customer_principal_id")
        ->assertJsonMissingPath("data.0.order_id");
});

it("reads a merchant's reviews as the ones bought from that merchant", function () {
    $this->fakeOrderClient->addOrder(deliveredOrder("ORD-DELIVERED-001"));
    $this->withHeaders(IdentityTokens::bearer())->postJson(
        "/api/v1/reviews/products",
        [
            "order_id" => "ORD-DELIVERED-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 2,
        ]
    );

    $response = $this->getJson("/api/v1/reviews/merchants/3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3");

    $response->assertStatus(200)
        ->assertJson(["total_reviews" => 1, "average_rating" => 2])
        ->assertJsonMissingPath("data.0.customer_principal_id");
});
