<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Models\DriverRating;
use App\Security\AccessClaims;
use InvalidArgumentException;

class DriverRatingService {
    public function __construct(
        private readonly DriverRatingRepositoryInterface $repository,
        private readonly OrderClientInterface $orderClient
    ) {}

    public function createRating(AccessClaims $caller, array $data): DriverRating {
        $order = $this->orderClient->getOrderDetails($data["order_id"]);
        if ($order === null) {
            throw new InvalidArgumentException("Order not found.");
        }

        if ($order->customerPrincipalId !== $caller->principalId) {
            throw new InvalidArgumentException("Order does not belong to this customer.");
        }

        if ($order->driverPrincipalId === $caller->principalId) {
            throw new InvalidArgumentException("A courier cannot rate its own delivery.");
        }

        if (! $order->delivered) {
            throw new InvalidArgumentException("Driver ratings are only allowed for DELIVERED or COMPLETED orders.");
        }

        if ($order->driverPrincipalId === "") {
            throw new InvalidArgumentException("Order records no courier who delivered it.");
        }

        return $this->repository->create([
            "order_id" => $data["order_id"],
            "customer_principal_id" => $caller->principalId,
            "driver_principal_id" => $order->driverPrincipalId,
            "rating" => $data["rating"],
            "comment" => $data["comment"] ?? null,
        ]);
    }

    public function getDriverRatingSummary(string $driverPrincipalId): array {
        $ratings = $this->repository->getPaginatedByDriverId($driverPrincipalId);
        $avgRating = $this->repository->getAverageRatingByDriverId($driverPrincipalId);
        $totalCount = $this->repository->getTotalCountByDriverId($driverPrincipalId);

        return [
            "driver_principal_id" => $driverPrincipalId,
            "average_rating" => $avgRating,
            "total_ratings" => $totalCount,
            "data" => $ratings->items(),
            "current_page" => $ratings->currentPage(),
            "last_page" => $ratings->lastPage(),
        ];
    }
}
