<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\OrderClientInterface;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;

class GrpcOrderClient extends BaseStub implements OrderClientInterface {
    public function __construct(string $hostname = "", array $opts = []) {
        if ($hostname === "") {
            $hostname = config("services.order_service.grpc_url", "localhost:50055");
        }
        $opts["credentials"] = $opts["credentials"] ?? ChannelCredentials::createInsecure();
        parent::__construct($hostname, $opts);
    }

    public function getOrderDetails(string $orderId): ?array {
        $requestPayload = pack("C C N A*", 0, 0, strlen($orderId) + 2, "\x0a" . chr(strlen($orderId)) . $orderId);

        [$response, $status] = $this->_simpleRequest(
            "/order.v1.OrderGrpcService/GetOrderDetails",
            $requestPayload,
            [self::class, "deserializeResponse"],
            []
        )->wait();

        if ($status->code !== \Grpc\STATUS_OK || ! $response) {
            return null;
        }

        return $response;
    }

    public static function deserializeResponse(string $value): array {
        return [
            "id" => $value,
            "found" => true,
        ];
    }
}
