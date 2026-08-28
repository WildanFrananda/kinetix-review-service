<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentDriverRatingRepository implements DriverRatingRepositoryInterface {
    public function create(array $data): DriverRating {
        return DriverRating::create($data);
    }

    public function getPaginatedByDriverId(int $driverId, int $perPage = 15): LengthAwarePaginator {
        return DriverRating::where("driver_id", $driverId)
            ->latest()
            ->paginate($perPage);
    }

    public function getAverageRatingByDriverId(int $driverId): float {
        $avg = DriverRating::where("driver_id", $driverId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByDriverId(int $driverId): int {
        return DriverRating::where("driver_id", $driverId)->count();
    }
}
