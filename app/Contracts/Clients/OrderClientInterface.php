<?php

declare(strict_types=1);

namespace App\Contracts\Clients;

use App\Clients\OrderDetails;

interface OrderClientInterface {
    public function getOrderDetails(string $orderId): ?OrderDetails;
}
