<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use App\Observability\RequestId;
use App\Security\ServiceIdentity;
use Grpc\ChannelCredentials;
use Order\V1\GetOrderDetailsRequest;
use Order\V1\GetOrderDetailsResponse;

final class GrpcOrderClient implements OrderClientInterface {
    private readonly \Order\V1\OrderServiceClient $stub;

    public function __construct(string $hostname = "", ?ChannelCredentials $credentials = null) {
        if ($hostname === "") {
            $hostname = config("services.order_service.grpc_url", "kinetix-order-service:50055");
        }

        $this->stub = new \Order\V1\OrderServiceClient($hostname, [
            "credentials" => $credentials ?? ServiceIdentity::channelCredentials(),
        ]);
    }

    public function getOrderDetails(string $orderId): ?array {
        $request = new GetOrderDetailsRequest();
        $request->setOrderId($orderId);

        /** @var array{0: ?GetOrderDetailsResponse, 1: \stdClass} $call */
        $call = $this->stub->GetOrderDetails($request, RequestId::metadata())->wait();
        [$response, $status] = $call;

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

    private static function major(?\Common\V1\Money $money): string {
        return $money === null ? "0.00" : number_format($money->getAmountMinor() / 100, 2, ".", "");
    }
}
