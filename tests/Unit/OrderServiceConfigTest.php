<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;

function servicesConfigWithout(string $variable): array {
    $server = $_SERVER[$variable] ?? null;
    $env = $_ENV[$variable] ?? null;
    $process = getenv($variable);
    unset($_SERVER[$variable], $_ENV[$variable]);
    putenv($variable);

    try {
        return require config_path("services.php");
    } finally {
        if ($server !== null) {
            $_SERVER[$variable] = $server;
        }

        if ($env !== null) {
            $_ENV[$variable] = $env;
        }

        if ($process !== false) {
            putenv("{$variable}={$process}");
        }
    }
}

it("names no order host of its own when ORDER_SERVICE_GRPC_URL is unset", function () {
    expect(servicesConfigWithout("ORDER_SERVICE_GRPC_URL")["order_service"]["grpc_url"])->toBeNull()
        ->and(env("ORDER_SERVICE_GRPC_URL"))->toBe("order.test:50055");
});

it("keeps no configuration for an identity gRPC client it does not have", function () {
    expect(require config_path("services.php"))->not->toHaveKey("identity_service");
});

it("refuses to build the order client Octane warms at boot without ORDER_SERVICE_GRPC_URL", function (?string $host) {
    expect(config("octane.warm"))->toContain(OrderClientInterface::class);

    config(["services.order_service.grpc_url" => $host]);
    $this->app->forgetInstance(OrderClientInterface::class);

    expect(fn () => $this->app->make(OrderClientInterface::class))
        ->toThrow(RuntimeException::class, "ORDER_SERVICE_GRPC_URL is required and has no default.");
})->with([
    "unset" => [null],
    "empty" => [""],
]);
