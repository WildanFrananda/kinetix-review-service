<?php

declare(strict_types=1);

namespace Tests;

use App\Clients\OrderDetails;
use App\Clients\OrderServiceUnavailableException;
use App\Contracts\Clients\OrderClientInterface;

class FakeOrderClient implements OrderClientInterface {
    private array $orders = [];

    private array $unreachable = [];

    private array $askedFor = [];

    public function addOrder(OrderDetails $order): void {
        $this->orders[self::spellingInsensitive($order->orderId)] = $order;
    }

    public function markUnreachable(string $orderId, ?int $retryAfterSeconds = null): void {
        $this->unreachable[self::spellingInsensitive($orderId)] = $retryAfterSeconds;
    }

    public function getOrderDetails(string $orderId): ?OrderDetails {
        $this->askedFor[] = $orderId;
        $key = self::spellingInsensitive($orderId);

        if (array_key_exists($key, $this->unreachable)) {
            $retryAfter = $this->unreachable[$key];

            throw $retryAfter === null
                ? OrderServiceUnavailableException::transport()
                : OrderServiceUnavailableException::breakerOpen($retryAfter);
        }

        return $this->orders[$key] ?? null;
    }

    public function askedFor(): array {
        return $this->askedFor;
    }

    private static function spellingInsensitive(string $orderId): string {
        return strtolower(str_replace(["{", "}", "(", ")", "-"], "", $orderId));
    }
}
