<?php

declare(strict_types=1);

namespace Tests;

use App\Clients\OrderServiceUnavailableException;
use App\Contracts\Clients\OrderClientInterface;

class FakeOrderClient implements OrderClientInterface {
    private array $orders = [];

    private array $unreachable = [];

    public function addOrder(string $orderId, array $data): void {
        $this->orders[$orderId] = $data;
    }

    public function markUnreachable(string $orderId, ?int $retryAfterSeconds = null): void {
        $this->unreachable[$orderId] = $retryAfterSeconds;
    }

    public function getOrderDetails(string $orderId): ?array {
        if (array_key_exists($orderId, $this->unreachable)) {
            $retryAfter = $this->unreachable[$orderId];

            throw $retryAfter === null
                ? OrderServiceUnavailableException::transport()
                : OrderServiceUnavailableException::breakerOpen($retryAfter);
        }

        return $this->orders[$orderId] ?? null;
    }
}
