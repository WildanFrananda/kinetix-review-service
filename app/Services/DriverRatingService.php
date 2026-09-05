<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Models\DriverRating;
use InvalidArgumentException;

class DriverRatingService {
    public function __construct(
        private readonly DriverRatingRepositoryInterface $repository,
        private readonly OrderClientInterface $orderClient
    ) {}

    public function createRating(int $customerId, array $data): DriverRating {
        $order = $this->orderClient->getOrderDetails($data["order_id"]);
        if (! $order) {
            throw new InvalidArgumentException("Order not found.");
        }

        $orderCustomerId = (int) ($order["customer_id"] ?? 0);
        if ($orderCustomerId !== $customerId) {
            throw new InvalidArgumentException("Order does not belong to this customer.");
        }

        $status = strtoupper((string) ($order["status"] ?? ""));
        if ($status !== "DELIVERED" && $status !== "COMPLETED") {
            throw new InvalidArgumentException("Driver ratings are only allowed for DELIVERED or COMPLETED orders.");
        }

        return $this->repository->create([
            "order_id" => $data["order_id"],
            "customer_id" => $customerId,
            "driver_id" => $data["driver_id"],
            "rating" => $data["rating"],
            "comment" => $data["comment"] ?? null,
        ]);
    }

    public function getDriverRatingSummary(int $driverId): array {
        $ratings = $this->repository->getPaginatedByDriverId($driverId);
        $avgRating = $this->repository->getAverageRatingByDriverId($driverId);
        $totalCount = $this->repository->getTotalCountByDriverId($driverId);

        return [
            "driver_id" => $driverId,
            "average_rating" => $avgRating,
            "total_ratings" => $totalCount,
            "data" => $ratings->items(),
            "current_page" => $ratings->currentPage(),
            "last_page" => $ratings->lastPage(),
        ];
    }
}
