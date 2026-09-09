<?php

declare(strict_types=1);

namespace App\Observability;

class MetricsRegistry {
    public const SERVICE = "kinetix-review-service";

    public const SCRAPE_ROUTE = "/api/metrics";

    public const UNMATCHED_ROUTE = "<unmatched>";

    public const OTHER_METHOD = "OTHER";

    private const METHODS = ["GET", "HEAD", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "TRACE", "CONNECT"];

    public const ORDER_PEER = "order";

    public const ORDER_METHOD = "/order.v1.OrderService/GetOrderDetails";

    private const DURATION_BUCKETS = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0,
    ];

    private readonly CounterFamily $httpRequests;

    private readonly HistogramFamily $httpDuration;

    private readonly CounterFamily $grpcClientCalls;

    private readonly CounterFamily $grpcClientShortCircuits;

    private readonly GaugeFamily $buildInfo;

    private readonly GaugeFamily $workerStart;

    public function __construct(string $version) {
        $this->httpRequests = new CounterFamily(
            "kinetix_http_requests_total",
            "HTTP requests served, by method, matched route template and response status.",
            ["method", "route", "status"]
        );

        $this->httpDuration = new HistogramFamily(
            "kinetix_http_request_duration_seconds",
            "Wall-clock seconds spent serving an HTTP request, measured around the whole middleware stack.",
            ["method", "route"],
            self::DURATION_BUCKETS
        );

        $this->grpcClientCalls = new CounterFamily(
            "kinetix_grpc_client_calls_total",
            "Outbound gRPC calls that reached a status, by peer, method and gRPC status name.",
            ["peer", "grpc_method", "grpc_code"]
        );

        $this->grpcClientShortCircuits = new CounterFamily(
            "kinetix_grpc_client_short_circuits_total",
            "Outbound gRPC calls the circuit breaker refused to make. These never reached the "
                . "peer and so have no gRPC status; counting them as UNAVAILABLE would say the "
                . "peer answered when nothing was asked.",
            ["peer"]
        );

        $this->buildInfo = new GaugeFamily(
            "kinetix_build_info",
            "Always 1. Carries the service name and the build version as labels.",
            ["service", "version"]
        );

        $this->workerStart = new GaugeFamily(
            "kinetix_octane_worker_start_time_seconds",
            "Unix time this Octane worker booted. A scrape is answered by one worker of several, "
                . "so a change in this value between two scrapes means the counters above came "
                . "from different workers and are not comparable.",
            []
        );

        $this->buildInfo->set(1.0, [self::SERVICE, $version]);
        $this->workerStart->set(microtime(true));

        $this->httpRequests->initialise(["GET", self::SCRAPE_ROUTE, "200"]);
        $this->httpDuration->initialise(["GET", self::SCRAPE_ROUTE]);
        $this->grpcClientCalls->initialise([self::ORDER_PEER, self::ORDER_METHOD, "OK"]);
        $this->grpcClientShortCircuits->initialise([self::ORDER_PEER]);
    }

    public function recordHttpRequest(string $method, string $route, int $status, float $seconds): void {
        $method = self::normaliseMethod($method);

        $this->httpRequests->increment([$method, $route, (string) $status]);
        $this->httpDuration->observe($seconds, [$method, $route]);
    }

    public function recordGrpcClientCall(string $peer, string $method, int $code): void {
        $this->grpcClientCalls->increment([$peer, $method, GrpcStatusName::of($code)]);
    }

    public function recordGrpcClientShortCircuit(string $peer): void {
        $this->grpcClientShortCircuits->increment([$peer]);
    }

    public function render(): string {
        return $this->httpRequests->render()
            . $this->httpDuration->render()
            . $this->grpcClientCalls->render()
            . $this->grpcClientShortCircuits->render()
            . $this->buildInfo->render()
            . $this->workerStart->render();
    }

    private static function normaliseMethod(string $method): string {
        $method = strtoupper($method);

        return in_array($method, self::METHODS, true) ? $method : self::OTHER_METHOD;
    }
}
