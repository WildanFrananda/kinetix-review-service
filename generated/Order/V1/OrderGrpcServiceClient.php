<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Order\V1;

/**
 */
class OrderGrpcServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Order\V1\GetOrderDetailsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetOrderDetails(\Order\V1\GetOrderDetailsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/order.v1.OrderGrpcService/GetOrderDetails',
        $argument,
        ['\Order\V1\GetOrderDetailsResponse', 'decode'],
        $metadata, $options);
    }

}
