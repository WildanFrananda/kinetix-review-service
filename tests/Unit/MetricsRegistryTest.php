<?php

declare(strict_types=1);

use App\Observability\FileMetricStore;
use App\Observability\InMemoryMetricStore;
use App\Observability\MetricsRegistry;
use Tests\MetricsGate;
use Tests\UnwritableMetricStore;

function metricsRegistry(string $version = "1.2.3"): MetricsRegistry {
    return new MetricsRegistry($version, new InMemoryMetricStore);
}

it("renders every metric the contract names for this service before anything has happened", function () {
    $body = metricsRegistry()->render();

    expect($body)->toContain("\nkinetix_http_requests_total{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_bucket{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_sum{");
    expect($body)->toContain("\nkinetix_http_request_duration_seconds_count{");
    expect($body)->toContain("\nkinetix_grpc_client_calls_total{");
    expect($body)->toContain("kinetix_build_info{service=\"kinetix-review-service\",version=\"1.2.3\"} 1");
});

it("does not claim to serve a gRPC surface it has none of", function () {
    $body = metricsRegistry()->render();

    expect($body)->not->toContain("kinetix_grpc_server_calls_total");
});

it("is Prometheus text: every family declares a HELP and a TYPE", function () {
    $body = metricsRegistry()->render();

    expect($body)->toStartWith("# HELP kinetix_http_requests_total ");

    $families = [
        "kinetix_http_requests_total" => "counter",
        "kinetix_http_request_duration_seconds" => "histogram",
        "kinetix_grpc_client_calls_total" => "counter",
        "kinetix_grpc_client_short_circuits_total" => "counter",
        "kinetix_metric_store_write_failures_total" => "counter",
        "kinetix_build_info" => "gauge",
    ];

    foreach ($families as $name => $type) {
        expect($body)->toContain("# HELP {$name} ");
        expect($body)->toContain("# TYPE {$name} {$type}");
    }
});

it("carries no identifier and no raw path in a label, on the default version", function () {
    $registry = metricsRegistry((string) config("app.version"));
    $registry->recordHttpRequest("GET", "/api/v1/reviews/products/{productId}", 200, 0.01);
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 5);

    $body = $registry->render();

    expect(preg_match(MetricsGate::LEAKED_IDENTIFIER, $body))->toBe(0);
    expect(preg_match(MetricsGate::UNTEMPLATED_ROUTE, $body))->toBe(0);
});

it("counts a request once, into the right bucket, sum and count", function () {
    $registry = metricsRegistry();
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
    $registry = metricsRegistry();
    $registry->recordHttpRequest("GET", "/api/health", 200, 30.0);

    $body = $registry->render();

    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="10"} 0');
    expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="+Inf"} 1');
    expect($body)->toContain('kinetix_http_request_duration_seconds_count{method="GET",route="/api/health"} 1');
});

it("collapses a method it does not recognise instead of opening a series for it", function () {
    $registry = metricsRegistry();
    $registry->recordHttpRequest("BREW", "/api/health", 405, 0.001);
    $registry->recordHttpRequest("PROPFIND", "/api/health", 405, 0.001);

    $body = $registry->render();

    expect($body)->toContain('kinetix_http_requests_total{method="OTHER",route="/api/health",status="405"} 2');
    expect($body)->not->toContain('method="BREW"');
});

it("names a gRPC status rather than numbering it, and says UNKNOWN for a code it has no name for", function () {
    $registry = metricsRegistry();
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 14);
    $registry->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 99);

    $body = $registry->render();

    expect($body)->toContain('grpc_code="UNAVAILABLE"} 1');
    expect($body)->toContain('grpc_code="UNKNOWN"} 1');
});

it("keeps a call the breaker refused out of the calls that reached a status", function () {
    $registry = metricsRegistry();
    $registry->recordGrpcClientShortCircuit("order");

    $body = $registry->render();

    expect($body)->toContain('kinetix_grpc_client_short_circuits_total{peer="order"} 1');
    expect($body)->toContain('grpc_code="OK"} 0');
    expect($body)->not->toContain('grpc_code="UNAVAILABLE"');
});

it("reports the whole service's traffic, not the slice one Octane worker happened to serve", function () {
    $path = storage_path("framework/testing/metrics-shared-" . bin2hex(random_bytes(8)) . ".json");

    try {
        $workerA = new MetricsRegistry("1.2.3", new FileMetricStore($path));
        $workerB = new MetricsRegistry("1.2.3", new FileMetricStore($path));

        $workerA->recordHttpRequest("GET", "/api/health", 200, 0.01);
        $workerA->recordHttpRequest("GET", "/api/health", 200, 0.01);
        $workerB->recordHttpRequest("GET", "/api/health", 200, 0.01);

        foreach ([$workerA->render(), $workerB->render()] as $body) {
            expect($body)->toContain('kinetix_http_requests_total{method="GET",route="/api/health",status="200"} 3');
            expect($body)->toContain('kinetix_http_request_duration_seconds_count{method="GET",route="/api/health"} 3');
            expect($body)->toContain('kinetix_http_request_duration_seconds_bucket{method="GET",route="/api/health",le="0.025"} 3');
        }
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it("keeps a gRPC call one worker made visible to the worker that answers the scrape", function () {
    $path = storage_path("framework/testing/metrics-shared-" . bin2hex(random_bytes(8)) . ".json");

    try {
        $workerA = new MetricsRegistry("1.2.3", new FileMetricStore($path));
        $workerB = new MetricsRegistry("1.2.3", new FileMetricStore($path));

        $workerA->recordGrpcClientCall("order", MetricsRegistry::ORDER_METHOD, 14);
        $workerA->recordGrpcClientShortCircuit("order");

        $body = $workerB->render();

        expect($body)->toContain('grpc_code="UNAVAILABLE"} 1');
        expect($body)->toContain('kinetix_grpc_client_short_circuits_total{peer="order"} 1');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it("shortens a build version that would otherwise read as an identifier in a label", function () {
    $body = metricsRegistry("9f2c1a4be7d035a86c1e5d7b3f0a2c9d81b4e6f7")->render();

    expect(preg_match(MetricsGate::LEAKED_IDENTIFIER, $body))->toBe(0);
    expect($body)->toContain("kinetix_build_info{service=\"kinetix-review-service\",version=\"9f2c1a4be7d035a8\"} 1");
});

it("says how many recorded events the store lost rather than exporting the gap as quiet zeroes", function () {
    $registry = new MetricsRegistry("1.2.3", new UnwritableMetricStore);

    $registry->recordHttpRequest("GET", "/api/health", 200, 0.01);
    $registry->recordHttpRequest("GET", "/api/health", 200, 0.01);

    expect($registry->render())->toContain("kinetix_metric_store_write_failures_total 3");
});

it("does not zero the counters when a worker is recycled under --max-requests", function () {
    $path = storage_path("framework/testing/metrics-shared-" . bin2hex(random_bytes(8)) . ".json");

    try {
        $before = new MetricsRegistry("1.2.3", new FileMetricStore($path));
        $before->recordHttpRequest("GET", "/api/health", 200, 0.01);
        unset($before);

        $recycled = new MetricsRegistry("1.2.3", new FileMetricStore($path));

        expect($recycled->render())
            ->toContain('kinetix_http_requests_total{method="GET",route="/api/health",status="200"} 1');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
