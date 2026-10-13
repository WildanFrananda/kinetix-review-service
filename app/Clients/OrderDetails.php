<?php

declare(strict_types=1);

namespace App\Clients;

final class OrderDetails {
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerPrincipalId,
        public readonly string $merchantPrincipalId,
        public readonly string $driverPrincipalId,
        public readonly bool $delivered,
        public readonly array $productIds
    ) {}

    public function contains(string $productId): bool {
        return in_array($productId, $this->productIds, true);
    }
}
