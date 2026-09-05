<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use App\Security\ServiceIdentity;
use Grpc\ChannelCredentials;
use Order\V1\GetOrderDetailsRequest;
use Order\V1\GetOrderDetailsResponse;

final class GrpcOrderClient implements OrderClientInterface {
    private readonly \Order\V1\OrderGrpcServiceClient $stub;

    public function __construct(string $hostname = "", ?ChannelCredentials $credentials = null) {
        if ($hostname === "") {
            $hostname = config("services.order_service.grpc_url", "kinetix-order-service:50055");
        }

        $this->stub = new \Order\V1\OrderGrpcServiceClient($hostname, [
            "credentials" => $credentials ?? ServiceIdentity::channelCredentials(),
        ]);
    }

    public function getOrderDetails(string $orderId): ?array {
        $request = new GetOrderDetailsRequest();
        $request->setOrderId($orderId);

        /** @var array{0: ?GetOrderDetailsResponse, 1: \stdClass} $call */
        $call = $this->stub->GetOrderDetails($request)->wait();
        [$response, $status] = $call;

        if ($status->code !== \Grpc\STATUS_OK || $response === null || ! $response->getFound()) {
            return null;
        }

        $items = [];
        foreach ($response->getItems() as $item) {
            $items[] = [
                "product_id" => $item->getProductId(),
                "product_title" => $item->getProductTitle(),
                "unit_price" => $item->getUnitPrice(),
                "quantity" => $item->getQuantity(),
                "line_subtotal" => $item->getLineSubtotal(),
            ];
        }

        return [
            "order_id" => $response->getOrderId(),
            "order_number" => $response->getOrderNumber(),
            "customer_id" => $response->getCustomerId(),
            "status" => $response->getStatus(),
            "subtotal" => $response->getSubtotal(),
            "discount_amount" => $response->getDiscountAmount(),
            "final_total" => $response->getFinalTotal(),
            "items" => $items,
        ];
    }
}
