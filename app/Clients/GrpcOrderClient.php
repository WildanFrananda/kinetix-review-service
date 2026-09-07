<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use App\Observability\RequestId;
use App\Resilience\CircuitBreaker;
use App\Security\ServiceIdentity;
use Grpc\ChannelCredentials;
use Illuminate\Support\Facades\Log;
use Order\V1\GetOrderDetailsRequest;
use Order\V1\GetOrderDetailsResponse;

final class GrpcOrderClient implements OrderClientInterface {
    private const TIMEOUT_MICROSECONDS = 5_000_000;

    private readonly \Order\V1\OrderServiceClient $stub;

    private readonly CircuitBreaker $breaker;

    public function __construct(string $hostname = "", ?ChannelCredentials $credentials = null) {
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
            throw OrderServiceUnavailableException::breakerOpen($this->breaker->retryAfterSeconds());
        }

        $request = new GetOrderDetailsRequest();
        $request->setOrderId($orderId);

        /** @var array{0: ?GetOrderDetailsResponse, 1: \stdClass} $call */
        $call = $this->stub->GetOrderDetails(
            $request,
            RequestId::metadata(),
            ["timeout" => self::TIMEOUT_MICROSECONDS]
        )->wait();
        [$response, $status] = $call;

        if (self::isTransportFailure($status->code)) {
            $this->breaker->recordFailure();

            Log::warning("order-service GetOrderDetails failed in transport", [
                "breaker" => $this->breaker->name(),
                "grpc_code" => $status->code,
                "grpc_details" => $status->details ?? "",
                "order_id" => $orderId,
                "request_id" => RequestId::current() ?? "-",
            ]);

            throw OrderServiceUnavailableException::transport();
        }

        $this->breaker->recordSuccess();

        if ($status->code !== \Grpc\STATUS_OK || $response === null || ! $response->getFound()) {
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

    private static function isTransportFailure(int $code): bool {
        return $code === \Grpc\STATUS_UNAVAILABLE
            || $code === \Grpc\STATUS_DEADLINE_EXCEEDED
            || $code === \Grpc\STATUS_RESOURCE_EXHAUSTED
            || $code === \Grpc\STATUS_INTERNAL;
    }

    private static function major(?\Common\V1\Money $money): string {
        return $money === null ? "0.00" : number_format($money->getAmountMinor() / 100, 2, ".", "");
    }
}
