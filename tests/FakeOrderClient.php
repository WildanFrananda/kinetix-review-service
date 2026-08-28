<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Clients\OrderClientInterface;

class FakeOrderClient implements OrderClientInterface {
    private array $orders = [];

    public function addOrder(string $orderId, array $data): void {
        $this->orders[$orderId] = $data;
    }

    public function getOrderDetails(string $orderId): ?array {
        return $this->orders[$orderId] ?? null;
    }
}
