<?php

declare(strict_types=1);

use App\Observability\MetricsRegistry;
use Tests\MetricsGate;

it("renders every metric the contract names for this service before anything has happened", function () {
    $body = (new MetricsRegistry("1.2.3"))->render();

    expect($body)->toContain("\nkinetix_http_requests_total{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_bucket{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_sum{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_count{");
    expect($body)->toContain("\nkinetix_grpc_client_calls_total{");
    expect($body)->toContain("kinetix_build_info{service=\"kinetix-review-service\",version=\"1.2.3\"} 1");
});

it("does not claim to serve a gRPC surface it has none of", function () {
    $body = (new MetricsRegistry("1.2.3"))->render();

    expect($body)->not->toContain("kinetix_grpc_server_calls_total");
});

it("is Prometheus text: every family declares a HELP and a TYPE", function () {
    $body = (new MetricsRegistry("1.2.3"))->render();

    expect($body)->toStartWith("# HELP kinetix_http_requests_total ");

    $families = [
        "kinetix_http_requests_total" => "counter",
        "kinetix_http_request_duration_seconds" => "histogram",
        "kinetix_grpc_client_calls_total" => "counter",
        "kinetix_grpc_client_short_circuits_total" => "counter",
        "kinetix_build_info" => "gauge",
        "kinetix_octane_worker_start_time_seconds" => "gauge",
    ];

    foreach ($families as $name => $type) {
        expect($body)->toContain("# HELP {$name} ");
        expect($body)->toContain("# TYPE {$name} {$type}");
    }
});

it("carries no identifier and no raw path in a label, on the default version", function () {
    $registry = new MetricsRegistry((string) config("app.version"));
    $registry->recordHttpRequest("GET", "/api/v1/reviews/products/{productId}", 200, 0.01);
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 5);

    $body = $registry->render();

    expect(preg_match(MetricsGate::LEAKED_IDENTIFIER, $body))->toBe(0);
    expect(preg_match(MetricsGate::UNTEMPLATED_ROUTE, $body))->toBe(0);
});

it("counts a request once, into the right bucket, sum and count", function () {
    $registry = new MetricsRegistry("1.2.3");
    $registry->recordHttpRequest("GET", "/api/health", 200, 0.03);

    $body = $registry->render();

    expect($body)->toContain('kinetix_http_requests_total{method="GET",route="/api/health",status="200"} 1');
    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="0.025"} 0');
    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="0.05"} 1');
    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="+Inf"} 1');
    expect($body)->toContain('kinetix_http_request_duration_seconds_sum{method="GET",route="/api/health"} 0.03');
    expect($body)->toContain('kinetix_http_request_duration_seconds_count{method="GET",route="/api/health"} 1');
});

it("puts an observation past the last bucket in +Inf and nowhere else", function () {
    $registry = new MetricsRegistry("1.2.3");
    $registry->recordHttpRequest("GET", "/api/health", 200, 30.0);

    $body = $registry->render();

    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="10"} 0');
    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="+Inf"} 1');
    expect($body)->toContain('kinetix_http_request_duration_seconds_count{method="GET",route="/api/health"} 1');
});

it("collapses a method it does not recognise instead of opening a series for it", function () {
    $registry = new MetricsRegistry("1.2.3");
    $registry->recordHttpRequest("BREW", "/api/health", 405, 0.001);
    $registry->recordHttpRequest("PROPFIND", "/api/health", 405, 0.001);

    $body = $registry->render();

    expect($body)->toContain('kinetix_http_requests_total{method="OTHER",route="/api/health",status="405"} 2');
    expect($body)->not->toContain('method="BREW"');
});

it("names a gRPC status rather than numbering it, and says UNKNOWN for a code it has no name for", function () {
    $registry = new MetricsRegistry("1.2.3");
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 14);
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 99);

    $body = $registry->render();

    expect($body)->toContain('grpc_code="UNAVAILABLE"} 1');
    expect($body)->toContain('grpc_code="UNKNOWN"} 1');
});

it("keeps a call the breaker refused out of the calls that reached a status", function () {
    $registry = new MetricsRegistry("1.2.3");
    $registry->recordGrpcClientShortCircuit("order");

    $body = $registry->render();

    expect($body)->toContain('kinetix_grpc_client_short_circuits_total{peer="order"} 1');
    expect($body)->toContain('grpc_code="OK"} 0');
    expect($body)->not->toContain('grpc_code="UNAVAILABLE"');
});
