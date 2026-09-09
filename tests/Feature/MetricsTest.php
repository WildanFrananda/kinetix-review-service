<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;
use App\Observability\MetricsRegistry;
use Illuminate\Support\Facades\Route;
use Tests\ExplodingMetricsRegistry;
use Tests\FakeOrderClient;
use Tests\MetricsGate;

beforeEach(function () {
    $this->app->instance(OrderClientInterface::class, new FakeOrderClient);
});

it("serves Prometheus text on /api/metrics without a token", function () {
    $response = $this->get("/api/metrics");

    $response->assertStatus(200);
    expect($response->headers->get("Content-Type"))->toStartWith("text/plain");
    expect($response->getContent())->toStartWith("# HELP ");
});

it("labels a route with its template and not with the id that was in the URL", function () {
    $this->get("/api/v1/reviews/products/8f3a1b2c-0000-4000-8000-abcdefabcdef")->assertStatus(200);

    $body = $this->get("/api/metrics")->getContent();

    expect($body)->toContain('route="/api/v1/reviews/products/{productId}"');
    expect($body)->not->toContain("8f3a1b2c");
});

it("gives an unrouted URL one label instead of one series per URL a scanner invents", function () {
    $this->get("/api/nope/" . uniqid());

    $body = $this->get("/api/metrics")->getContent();

    expect($body)->toContain('route="<unmatched>"');
});

it("counts a request that ended in an exception as the status the caller received", function () {
    Route::get("/api/boom", function (): never {
        throw new RuntimeException("deliberate");
    });

    $this->get("/api/boom")->assertStatus(500);

    $body = $this->get("/api/metrics")->getContent();

    expect($body)->toContain('kinetix_http_requests_total{method="GET",route="/api/boom",status="500"} 1');
});

it("holds the estate's naming contract on a live body", function () {
    $this->get("/api/health");

    $body = $this->get("/api/metrics")->getContent();

    foreach (["kinetix_http_requests_total", "kinetix_http_request_duration_seconds", "kinetix_build_info"] as $name) {
        expect(preg_match('/^' . preg_quote($name, "/") . '/m', $body))->toBe(1);
    }

    expect(preg_match('/^kinetix_grpc_client_calls_total/m', $body))->toBe(1);
    expect($body)->not->toContain("kinetix_grpc_server_calls_total");

    expect(preg_match(MetricsGate::LEAKED_IDENTIFIER, $body))->toBe(0);
    expect(preg_match(MetricsGate::UNTEMPLATED_ROUTE, $body))->toBe(0);
});

it("names the service only on build_info, never on the series a scrape target already names", function () {
    $body = $this->get("/api/metrics")->getContent();

    $serviceLabels = preg_match_all('/service="[^"]*"/', $body);

    expect($serviceLabels)->toBe(1);
    expect($body)->toContain('service="' . MetricsRegistry::SERVICE . '"');
});

it("answers a registry it cannot render with a failed scrape, not with zeroes", function () {
    $this->app->instance(MetricsRegistry::class, new ExplodingMetricsRegistry);

    $response = $this->get("/api/metrics");

    $response->assertStatus(503);
    expect($response->getContent())->not->toContain("kinetix_http_requests_total{");
    expect($response->getContent())->toContain("not a report of zero traffic");
});
