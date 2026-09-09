<?php

declare(strict_types=1);

use App\Observability\SampleKey;

it("gives back exactly the parts it was built from", function () {
    $key = SampleKey::encode("kinetix_http_requests_total", "", ["GET", "/api/v1/reviews/products/{productId}", "500"]);

    expect(SampleKey::decode($key, "kinetix_http_requests_total"))->toBe([
        "kind" => "",
        "labels" => ["GET", "/api/v1/reviews/products/{productId}", "500"],
    ]);
});

it("keeps a family's samples out of another family's rendering", function () {
    $key = SampleKey::encode("kinetix_http_requests_total", "", ["GET"]);

    expect(SampleKey::decode($key, "kinetix_grpc_client_calls_total"))->toBeNull();
});

it("does not confuse a name with a name it is a prefix of", function () {
    $key = SampleKey::encode("kinetix_http_request_duration_seconds", "sum", ["GET", "/api/health"]);

    expect(SampleKey::decode($key, "kinetix_http_request"))->toBeNull();
    expect(SampleKey::decode($key, "kinetix_http_request_duration_seconds"))->not->toBeNull();
});

it("cannot be split into more parts than it was given, whatever is in a label", function () {
    $key = SampleKey::encode("kinetix_http_requests_total", "", ["GET\x1fPOST", "/api/health"]);

    expect(SampleKey::decode($key, "kinetix_http_requests_total"))->toBe([
        "kind" => "",
        "labels" => ["GETPOST", "/api/health"],
    ]);
});
