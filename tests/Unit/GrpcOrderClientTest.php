<?php

declare(strict_types=1);

use App\Clients\GrpcOrderClient;

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
