<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use App\Observability\MetricsRegistry;
use App\Observability\RequestId;
use App\Resilience\CircuitBreaker;
use App\Security\ServiceIdentity;
use Common\V1\OrderStatus;
use Grpc\ChannelCredentials;
use Illuminate\Support\Facades\Log;
use Order\V1\GetOrderDetailsRequest;
use Order\V1\GetOrderDetailsResponse;
use Order\V1\OrderServiceClient;
use Throwable;

final class GrpcOrderClient implements OrderClientInterface {
    private const TIMEOUT_MICROSECONDS = 5_000_000;

    private const STATUS_OK = 0;

    private const STATUS_UNKNOWN = 2;

    private const STATUS_INVALID_ARGUMENT = 3;

    private const STATUS_NOT_FOUND = 5;

    private const DELIVERED_STATUSES = [
        OrderStatus::ORDER_STATUS_DELIVERED,
        OrderStatus::ORDER_STATUS_COMPLETED,
    ];

    private readonly OrderServiceClient $stub;

    private readonly CircuitBreaker $breaker;

    public function __construct(
        private readonly MetricsRegistry $metrics,
        string $hostname = "",
        ?ChannelCredentials $credentials = null
    ) {
        if ($hostname === "") {
            $hostname = config("services.order_service.grpc_url", "kinetix-order-service:50055");
        }

        $this->stub = new OrderServiceClient(
            $hostname,
            [
                "credentials" => $credentials ?? ServiceIdentity::channelCredentials(),
            ]
        );

        $this->breaker = new CircuitBreaker("order-service");
    }

    public function getOrderDetails(string $orderId): ?OrderDetails {
        if (! $this->breaker->allows()) {
            $this->metrics->recordGrpcClientShortCircuit(MetricsRegistry::ORDER_PEER);

            throw OrderServiceUnavailableException::breakerOpen($this->breaker->retryAfterSeconds());
        }

        try {
            $request = new GetOrderDetailsRequest;
            $request->setOrderId($orderId);

            /** @var array{0: ?GetOrderDetailsResponse, 1: \stdClass} $call */
            $call = $this->stub->GetOrderDetails(
                $request,
                RequestId::metadata(),
                ["timeout" => self::TIMEOUT_MICROSECONDS]
            )->wait();
            [$response, $status] = $call;

            $answered = self::isAnswerAboutTheOrder($status->code);
            $code = $status->code;
            $details = $status->details ?? "";

            $this->metrics->recordGrpcClientCall(
                MetricsRegistry::ORDER_PEER,
                MetricsRegistry::ORDER_METHOD,
                $code
            );
        } catch (Throwable $ex) {
            $this->metrics->recordGrpcClientCall(
                MetricsRegistry::ORDER_PEER,
                MetricsRegistry::ORDER_METHOD,
                self::STATUS_UNKNOWN
            );

            $this->breaker->recordFailure();

            Log::warning(
                "order-service GetOrderDetails threw before answering",
                [
                    "breaker" => $this->breaker->name(),
                    "exception" => $ex::class,
                    "message" => $ex->getMessage(),
                    "order_id" => $orderId,
                    "request_id" => RequestId::current() ?? "-",
                ]
            );

            throw OrderServiceUnavailableException::transport();
        }

        if (! $answered) {
            $this->breaker->recordFailure();

            Log::warning(
                "order-service GetOrderDetails did not answer about the order",
                [
                    "breaker" => $this->breaker->name(),
                    "grpc_code" => $code,
                    "grpc_details" => $details,
                    "order_id" => $orderId,
                    "request_id" => RequestId::current() ?? "-",
                ]
            );

            throw OrderServiceUnavailableException::transport();
        }

        $this->breaker->recordSuccess();

        if ($code !== self::STATUS_OK || $response === null || ! $response->getFound()) {
            return null;
        }

        return self::detailsOf($response);
    }

    private static function detailsOf(GetOrderDetailsResponse $response): OrderDetails {
        $productIds = [];
        foreach ($response->getLines() as $line) {
            $productIds[] = $line->getProductId();
        }

        return new OrderDetails(
            $response->getOrderId(),
            $response->getCustomerPrincipalId(),
            $response->getMerchantPrincipalId(),
            $response->getDriverPrincipalId(),
            in_array($response->getStatus(), self::DELIVERED_STATUSES, true),
            $productIds
        );
    }

    private static function isAnswerAboutTheOrder(int $code): bool {
        return $code === self::STATUS_OK
            || $code === self::STATUS_NOT_FOUND
            || $code === self::STATUS_INVALID_ARGUMENT;
    }
}
