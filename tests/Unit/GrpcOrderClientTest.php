<?php

declare(strict_types=1);

use App\Clients\GrpcOrderClient;
use App\Clients\OrderDetails;
use App\Clients\OrderServiceUnavailableException;
use App\Observability\MetricsRegistry;
use Common\V1\OrderStatus;
use Grpc\ChannelCredentials;
use Illuminate\Support\Facades\Log;
use Order\V1\GetOrderDetailsResponse;
use Order\V1\OrderLine;

$classify = function (int $code): bool {
    $method = new ReflectionMethod(GrpcOrderClient::class, "isAnswerAboutTheOrder");

    return (bool) $method->invoke(null, $code);
};

it("counts OK, NOT_FOUND and INVALID_ARGUMENT as answers about the order", function () use ($classify) {
    expect($classify(0))->toBeTrue();  // OK
    expect($classify(5))->toBeTrue();  // NOT_FOUND: order-service looked, there is no such order
    expect($classify(3))->toBeTrue();  // INVALID_ARGUMENT: we sent a malformed order_id
});

it("counts every other status as a failure to ask, not as an answer", function () use ($classify) {
    expect($classify(16))->toBeFalse(); // UNAUTHENTICATED: our SPIFFE cert expired
    expect($classify(7))->toBeFalse();  // PERMISSION_DENIED: we fell off order's allowlist
    expect($classify(2))->toBeFalse();  // UNKNOWN: order threw and mapped it by default
    expect($classify(12))->toBeFalse(); // UNIMPLEMENTED: proto skew
    expect($classify(14))->toBeFalse(); // UNAVAILABLE
    expect($classify(4))->toBeFalse();  // DEADLINE_EXCEEDED
    expect($classify(8))->toBeFalse();  // RESOURCE_EXHAUSTED
    expect($classify(13))->toBeFalse(); // INTERNAL
    expect($classify(1))->toBeFalse();  // CANCELLED
    expect($classify(9))->toBeFalse();  // FAILED_PRECONDITION
    expect($classify(15))->toBeFalse(); // DATA_LOSS
});

it("pins the same wire values the grpc extension does", function () {
    $codes = new ReflectionClass(GrpcOrderClient::class);

    expect($codes->getConstant("STATUS_OK"))->toBe(\Grpc\STATUS_OK);
    expect($codes->getConstant("STATUS_NOT_FOUND"))->toBe(\Grpc\STATUS_NOT_FOUND);
    expect($codes->getConstant("STATUS_INVALID_ARGUMENT"))->toBe(\Grpc\STATUS_INVALID_ARGUMENT);
})->skip(fn (): bool => ! extension_loaded("grpc"), "ext-grpc is not loaded on this machine");

$detailsOf = function (GetOrderDetailsResponse $response): OrderDetails {
    $method = new ReflectionMethod(GrpcOrderClient::class, "detailsOf");

    return $method->invoke(null, $response);
};

function orderAnswer(int $status): GetOrderDetailsResponse {
    $line = new OrderLine;
    $line->setProductId("TSHIRT-BLK-M");

    $response = new GetOrderDetailsResponse;
    $response->setFound(true);
    $response->setOrderId("5c0f2a8e-9d41-4b7a-8e3c-1f6d2b9a7e05");
    $response->setCustomerPrincipalId("11111111-2222-3333-4444-555555555555");
    $response->setMerchantPrincipalId("3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3");
    $response->setDriverPrincipalId("b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914");
    $response->setStatus($status);
    $response->setLines([$line]);

    return $response;
}

it("reads an order order-service calls delivered or completed as delivered", function () use ($detailsOf) {
    expect($detailsOf(orderAnswer(OrderStatus::ORDER_STATUS_DELIVERED))->delivered)->toBeTrue();
    expect($detailsOf(orderAnswer(OrderStatus::ORDER_STATUS_COMPLETED))->delivered)->toBeTrue();
});

it("reads every other order status as not delivered", function () use ($detailsOf) {
    $undelivered = [
        OrderStatus::ORDER_STATUS_UNSPECIFIED,
        OrderStatus::ORDER_STATUS_PENDING_PAYMENT,
        OrderStatus::ORDER_STATUS_PAID,
        OrderStatus::ORDER_STATUS_PACKING,
        OrderStatus::ORDER_STATUS_IN_TRANSIT,
        OrderStatus::ORDER_STATUS_CANCELLED,
        OrderStatus::ORDER_STATUS_REFUNDED,
    ];

    foreach ($undelivered as $status) {
        expect($detailsOf(orderAnswer($status))->delivered)->toBeFalse();
    }
});

it("carries the order's buyer, merchant, courier and what was bought", function () use ($detailsOf) {
    $details = $detailsOf(orderAnswer(OrderStatus::ORDER_STATUS_DELIVERED));

    expect($details->customerPrincipalId)->toBe("11111111-2222-3333-4444-555555555555");
    expect($details->merchantPrincipalId)->toBe("3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3");
    expect($details->driverPrincipalId)->toBe("b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914");
    expect($details->contains("TSHIRT-BLK-M"))->toBeTrue();
    expect($details->contains("SOMETHING-NEVER-BOUGHT"))->toBeFalse();
});

it("asks ext-grpc for a channel of its own, never the process-wide persistent one", function () {
    $client = new ReflectionClass(GrpcOrderClient::class);

    expect($client->getConstant("CHANNEL_ARGUMENTS"))->toBe(["force_new" => true]);
});

it("builds its stub under the process-wide setup lock and lets the lock go", function () {
    new GrpcOrderClient(app(MetricsRegistry::class), "127.0.0.1:1", ChannelCredentials::createSsl());

    $lock = fopen(storage_path("framework/grpc-setup.lock"), "c");
    $free = flock($lock, LOCK_EX | LOCK_NB);
    flock($lock, LOCK_UN);
    fclose($lock);

    expect($free)->toBeTrue();
})->skip(fn (): bool => ! extension_loaded("grpc"), "ext-grpc is not loaded on this machine");

it("calls over the channel it built, so a port nobody serves answers UNAVAILABLE", function () {
    Log::shouldReceive("warning")
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === "order-service GetOrderDetails did not answer about the order"
                && $context["grpc_code"] === \Grpc\STATUS_UNAVAILABLE;
        });

    $client = new GrpcOrderClient(app(MetricsRegistry::class), "127.0.0.1:1", ChannelCredentials::createSsl());

    expect(fn () => $client->getOrderDetails("5c0f2a8e-9d41-4b7a-8e3c-1f6d2b9a7e05"))
        ->toThrow(OrderServiceUnavailableException::class);
})->skip(fn (): bool => ! extension_loaded("grpc"), "ext-grpc is not loaded on this machine");
