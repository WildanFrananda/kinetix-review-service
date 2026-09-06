<?php

declare(strict_types=1);

namespace Order\V1;

/**
 * Hand-written, and only this file.
 *
 * `protoc --php_out` generates the message classes above; the service stub needs
 * `grpc_php_plugin`, which is a separate binary this repository does not carry. The generated
 * stub is fifteen lines of `_simpleRequest` and nothing else, so it is written here rather than
 * pulling a toolchain in to produce it — and written against the CONTRACT's method path, which
 * is what the previous copy got wrong: it called `/order.v1.OrderGrpcService/GetOrderDetails`,
 * a service name order stopped serving when it conformed to the contract.
 */
class OrderServiceClient extends \Grpc\BaseStub {
    /**
     * @param string $hostname
     * @param array<string, mixed> $opts
     * @param \Grpc\Channel|null $channel
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     */
    public function GetOrderDetails(
        GetOrderDetailsRequest $argument,
        $metadata = [],
        $options = []
    ): \Grpc\UnaryCall {
        return $this->_simpleRequest(
            '/order.v1.OrderService/GetOrderDetails',
            $argument,
            [GetOrderDetailsResponse::class, 'decode'],
            $metadata,
            $options
        );
    }
}
