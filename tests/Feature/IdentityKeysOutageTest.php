<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;
use App\Security\TokenVerifier;
use Firebase\JWT\JWT;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

beforeEach(function () {
    $this->app->instance(OrderClientInterface::class, new FakeOrderClient);
});

function identityDown(): ConnectException {
    return new ConnectException(
        "cURL error 7: Failed to connect to identity.test port 80",
        new Request("GET", "http://identity.test/.well-known/jwks.json")
    );
}

function reviewWith(array $headers): array {
    return [
        $headers,
        [
            "order_id" => "ORD-OUTAGE-001",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
        ],
    ];
}

function tokenUnderAKeyIdentityNeverPublished(): string {
    $key = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);

    return JWT::encode(
        [
            "sub" => "11111111-2222-3333-4444-555555555555",
            "email" => "customer@kinetix.test",
            "role" => "customer",
            "token_use" => "access",
            "iss" => env("JWT_ISSUER"),
            "aud" => env("JWT_AUDIENCE"),
            "exp" => time() + 900,
        ],
        $key,
        "RS256",
        "a-kid-identity-never-published"
    );
}

it("answers 503, not 401, and logs it, when identity cannot be reached for its signing keys", function () {
    Log::spy();
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifierAnswering(new MockHandler([identityDown()])));
    [$headers, $body] = reviewWith([...IdentityTokens::bearer(), "X-Request-Id" => "req-jwks-down"]);

    $response = $this->withHeaders($headers)->postJson("/api/v1/reviews/products", $body);

    $response->assertStatus(503)->assertJson([
        "error" => "SERVICE_UNAVAILABLE",
        "traceId" => "req-jwks-down",
    ]);
    $this->assertDatabaseCount("product_reviews", 0);
    Log::shouldHaveReceived("warning")
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($context["reason"], "ConnectException")
                && $context["request_id"] === "req-jwks-down";
        })
        ->once();
});

it("answers 503, not 401, when identity answers something that is not its key set", function (Response $answer) {
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifierAnswering(new MockHandler([$answer])));
    [$headers, $body] = reviewWith(IdentityTokens::bearer());

    $response = $this->withHeaders($headers)->postJson("/api/v1/reviews/drivers", $body);

    $response->assertStatus(503)->assertJson(["error" => "SERVICE_UNAVAILABLE"]);
    $this->assertDatabaseCount("driver_ratings", 0);
})->with([
    "a 503" => fn () => new Response(503),
    "a 500" => fn () => new Response(500, [], "internal error"),
    "a 404" => fn () => new Response(404),
    "a page that is not JSON" => fn () => new Response(200, [], "<html></html>"),
    "a key set with no keys" => fn () => new Response(200, [], "{\"keys\": []}"),
    "JSON with no key set" => fn () => new Response(200, [], "{\"status\": \"ok\"}"),
]);

it("keeps answering 503 while identity stays down, after its key fetches are rate limited", function () {
    $identity = new MockHandler(array_fill(0, 15, identityDown()));
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifierAnswering($identity));
    [$headers, $body] = reviewWith(IdentityTokens::bearer());

    $statuses = [];
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $statuses[] = $this->withHeaders($headers)->postJson("/api/v1/reviews/products", $body)->status();
    }

    expect($statuses)->toBe(array_fill(0, 12, 503))
        ->and($identity->count())->toBe(5);
});

it("answers 401 again for a key identity never published, once identity answers", function () {
    $identity = new MockHandler([...array_fill(0, 9, identityDown()), IdentityTokens::jwksResponse()]);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifierAnswering($identity));
    [$headers, $body] = reviewWith(IdentityTokens::bearer());
    $forged = ["Authorization" => "Bearer " . tokenUnderAKeyIdentityNeverPublished()];

    for ($attempt = 0; $attempt < 9; $attempt++) {
        $this->withHeaders($headers)->postJson("/api/v1/reviews/products", $body)->assertStatus(503);
    }

    $this->withHeaders($forged)->postJson("/api/v1/reviews/products", $body)->assertStatus(401);

    $this->withHeaders($forged)->postJson("/api/v1/reviews/products", $body)->assertStatus(401);
    expect($identity->count())->toBe(0);
});

it("still verifies tokens from the keys it holds while identity is down", function () {
    $identity = new MockHandler([IdentityTokens::jwksResponse(), identityDown(), identityDown()]);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifierAnswering($identity));
    [$headers, $body] = reviewWith(IdentityTokens::bearer());

    $this->withHeaders($headers)
        ->postJson("/api/v1/reviews/products", $body)
        ->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY", "message" => "Order not found."]);

    $this->withHeaders($headers)
        ->postJson("/api/v1/reviews/products", $body)
        ->assertStatus(422)
        ->assertJson(["error" => "UNPROCESSABLE_ENTITY", "message" => "Order not found."]);
    expect($identity->count())->toBe(2);
});
