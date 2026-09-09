<?php

declare(strict_types=1);

namespace App\Observability;

use App\Contracts\Observability\MetricStoreInterface;
use Illuminate\Support\Facades\Log;

final class MetricsRegistry {
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

    private readonly MetricStoreInterface $workerLocal;

    private readonly CounterFamily $httpRequests;

    private readonly HistogramFamily $httpDuration;

    private readonly CounterFamily $grpcClientCalls;

    private readonly CounterFamily $grpcClientShortCircuits;

    private readonly CounterFamily $storeWriteFailures;

    private readonly GaugeFamily $buildInfo;

    private bool $writeFailureLogged = false;

    public function __construct(string $version, private readonly MetricStoreInterface $store) {
        $this->workerLocal = new InMemoryMetricStore;

        $this->httpRequests = new CounterFamily(
            $this->store,
            "kinetix_http_requests_total",
            "HTTP requests served, by method, matched route template and response status.",
            ["method", "route", "status"]
        );

        $this->httpDuration = new HistogramFamily(
            $this->store,
            "kinetix_http_request_duration_seconds",
            "Wall-clock seconds spent serving an HTTP request, measured around the whole middleware stack.",
            ["method", "route"],
            self::DURATION_BUCKETS
        );

        $this->grpcClientCalls = new CounterFamily(
            $this->store,
            "kinetix_grpc_client_calls_total",
            "Outbound gRPC calls that reached a status, by peer, method and gRPC status name.",
            ["peer", "grpc_method", "grpc_code"]
        );

        $this->grpcClientShortCircuits = new CounterFamily(
            $this->store,
            "kinetix_grpc_client_short_circuits_total",
            "Outbound gRPC calls the circuit breaker refused to make. These never reached the "
                . "peer and so have no gRPC status; counting them as UNAVAILABLE would say the "
                . "peer answered when nothing was asked.",
            ["peer"]
        );

        $this->storeWriteFailures = new CounterFamily(
            $this->workerLocal,
            "kinetix_metric_store_write_failures_total",
            "Recorded events this worker could not write to the shared metric store, and so lost. "
                . "Anything above zero means the counters above are an undercount by at least this "
                . "much. Kept per worker, because a count of the shared store's failures cannot "
                . "live in the shared store.",
            []
        );

        $this->buildInfo = new GaugeFamily(
            "kinetix_build_info",
            "Always 1. Carries the service name and the build version as labels.",
            ["service", "version"]
        );

        $this->buildInfo->set(1.0, [self::SERVICE, VersionLabel::of($version)]);

        $this->storeWriteFailures->initialise([]);
        $this->httpRequests->initialise(["GET", self::SCRAPE_ROUTE, "200"]);
        $this->httpDuration->initialise(["GET", self::SCRAPE_ROUTE]);
        $this->grpcClientCalls->initialise([self::ORDER_PEER, self::ORDER_METHOD, "OK"]);
        $this->grpcClientShortCircuits->initialise([self::ORDER_PEER]);

        $this->commit();
    }

    public function recordHttpRequest(string $method, string $route, int $status, float $seconds): void {
        $method = self::normaliseMethod($method);

        $this->httpRequests->increment([$method, $route, (string) $status]);
        $this->httpDuration->observe($seconds, [$method, $route]);

        $this->commit();
    }

    public function recordGrpcClientCall(string $peer, string $method, int $code): void {
        $this->grpcClientCalls->increment([$peer, $method, GrpcStatusName::of($code)]);

        $this->commit();
    }

    public function recordGrpcClientShortCircuit(string $peer): void {
        $this->grpcClientShortCircuits->increment([$peer]);

        $this->commit();
    }

    /**
     * @throws MetricStoreUnavailableException
     */
    public function render(): string {
        $samples = $this->store->read();
        $local = $this->workerLocal->read();

        return $this->httpRequests->render($samples)
            . $this->httpDuration->render($samples)
            . $this->grpcClientCalls->render($samples)
            . $this->grpcClientShortCircuits->render($samples)
            . $this->storeWriteFailures->render($local)
            . $this->buildInfo->render();
    }

    private function commit(): void {
        try {
            $this->store->commit();
        } catch (MetricStoreUnavailableException $exception) {
            $this->storeWriteFailures->increment([]);

            if ($this->writeFailureLogged) {
                return;
            }

            $this->writeFailureLogged = true;

            Log::error("the shared metric store rejected a write; these counts are lost and the "
                . "exported counters are now an undercount", [
                    "exception" => $exception::class,
                    "message" => $exception->getMessage(),
                    "counted_by" => "kinetix_metric_store_write_failures_total",
                ]
            );
        }
    }

    private static function normaliseMethod(string $method): string {
        $method = strtoupper($method);

        return in_array($method, self::METHODS, true) ? $method : self::OTHER_METHOD;
    }
}
