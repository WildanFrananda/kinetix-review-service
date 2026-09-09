<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use App\Observability\MetricsRegistry;
use App\Observability\RequestId;
use App\Resilience\CircuitBreaker;
use App\Security\ServiceIdentity;
use Grpc\ChannelCredentials;
use Illuminate\Support\Facades\Log;
use Order\V1\GetOrderDetailsRequest;
use Order\V1\GetOrderDetailsResponse;

final class GrpcOrderClient implements OrderClientInterface {
    private const TIMEOUT_MICROSECONDS = 5_000_000;

    private const STATUS_OK = 0;

    private const STATUS_UNKNOWN = 2;

    private const STATUS_INVALID_ARGUMENT = 3;

    private const STATUS_NOT_FOUND = 5;

    private readonly \Order\V1\OrderServiceClient $stub;

    private readonly CircuitBreaker $breaker;

    public function __construct(
        private readonly MetricsRegistry $metrics,
        string $hostname = "",
        ?ChannelCredentials $credentials = null
    ) {
        if ($hostname === "") {
            $hostname = config("services.order_service.grpc_url", "kinetix-order-service:50055");
        }

        $this->stub = new \Order\V1\OrderServiceClient($hostname, [
            "credentials" => $credentials ?? ServiceIdentity::channelCredentials(),
        ]);

        $this->breaker = new CircuitBreaker("order-service");
    }

    public function getOrderDetails(string $orderId): ?array {
        if (! $this->breaker->allows()) {
            $this->metrics->recordGrpcClientShortCircuit(MetricsRegistry::ORDER_PEER);

            throw OrderServiceUnavailableException::breakerOpen($this->breaker->retryAfterSeconds());
        }

        try {
            $request = new GetOrderDetailsRequest();
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
        } catch (\Throwable $ex) {
            $this->metrics->recordGrpcClientCall(
                MetricsRegistry::ORDER_PEER,
                MetricsRegistry::ORDER_METHOD,
                self::STATUS_UNKNOWN
            );

            $this->breaker->recordFailure();

            Log::warning("order-service GetOrderDetails threw before answering", [
                "breaker" => $this->breaker->name(),
                "exception" => $ex::class,
                "message" => $ex->getMessage(),
                "order_id" => $orderId,
                "request_id" => RequestId::current() ?? "-",
            ]);

            throw OrderServiceUnavailableException::transport();
        }

        if (! $answered) {
            $this->breaker->recordFailure();

            Log::warning("order-service GetOrderDetails did not answer about the order", [
                "breaker" => $this->breaker->name(),
                "grpc_code" => $code,
                "grpc_details" => $details,
                "order_id" => $orderId,
                "request_id" => RequestId::current() ?? "-",
            ]);

            throw OrderServiceUnavailableException::transport();
        }

        $this->breaker->recordSuccess();

        if ($code !== self::STATUS_OK || $response === null || ! $response->getFound()) {
            return null;
        }

        $lines = [];
        foreach ($response->getLines() as $line) {
            $lines[] = [
                "product_id" => $line->getProductId(),
                "product_title" => $line->getProductTitle(),
                "unit_price" => self::major($line->getUnitPrice()),
                "quantity" => $line->getQuantity(),
                "line_subtotal" => self::major($line->getLineSubtotal()),
            ];
        }

        return [
            "order_id" => $response->getOrderId(),
            "order_number" => $response->getOrderNumber(),
            "customer_principal_id" => $response->getCustomerPrincipalId(),
            "status" => \Common\V1\OrderStatus::name($response->getStatus()),
            "subtotal" => self::major($response->getSubtotal()),
            "discount_amount" => self::major($response->getDiscountAmount()),
            "final_total" => self::major($response->getFinalTotal()),
            "items" => $lines,
        ];
    }

    private static function isAnswerAboutTheOrder(int $code): bool {
        return $code === self::STATUS_OK
            || $code === self::STATUS_NOT_FOUND
            || $code === self::STATUS_INVALID_ARGUMENT;
    }

    private static function major(?\Common\V1\Money $money): string {
        return $money === null ? "0.00" : number_format($money->getAmountMinor() / 100, 2, ".", "");
    }
}
