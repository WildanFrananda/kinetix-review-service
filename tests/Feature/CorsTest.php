<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;
use Illuminate\Testing\TestResponse;
use Tests\FakeOrderClient;

beforeEach(function () {
    $this->app->instance(OrderClientInterface::class, new FakeOrderClient);
});

function corsHeadersOf(TestResponse $response): array {
    return array_values(array_filter(
        array_keys($response->headers->all()),
        fn (string $name): bool => str_starts_with(strtolower($name), "access-control-")
    ));
}

it("answers a browser's preflight with no CORS grant, whatever the origin", function (string $path, string $allow) {
    $response = $this->withHeaders([
        "Origin" => "https://somewhere-else.example",
        "Access-Control-Request-Method" => "POST",
        "Access-Control-Request-Headers" => "authorization, content-type",
    ])->options($path);

    $response->assertStatus(200)->assertHeader("Allow", $allow);
    expect(corsHeadersOf($response))->toBe([]);
})->with([
    "a write" => ["/api/v1/reviews/products", "POST"],
    "a listing" => ["/api/v1/reviews/products/TSHIRT-BLK-M", "GET,HEAD"],
]);

it("answers a preflight on a path with no route as not found, with no CORS grant", function () {
    $response = $this->withHeaders([
        "Origin" => "https://somewhere-else.example",
        "Access-Control-Request-Method" => "GET",
    ])->options("/api/v1/reviews");

    $response->assertStatus(404)->assertJson(["error" => "REQUEST_REFUSED"]);
    expect(corsHeadersOf($response))->toBe([]);
});

it("answers a request that names an origin with no CORS header", function () {
    $response = $this->withHeader("Origin", "https://somewhere-else.example")
        ->getJson("/api/v1/reviews/products/TSHIRT-BLK-M");

    $response->assertStatus(200);
    expect(corsHeadersOf($response))->toBe([]);
});
