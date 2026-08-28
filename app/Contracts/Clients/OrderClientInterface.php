<?php

declare(strict_types=1);

namespace App\Contracts\Clients;

interface OrderClientInterface {
    public function getOrderDetails(string $orderId): ?array;
}
