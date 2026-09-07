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

    public function getPaginatedByDriverId(string $driverPrincipalId, int $perPage = 15): LengthAwarePaginator {
        return DriverRating::where("driver_principal_id", $driverPrincipalId)
            ->latest()
            ->paginate($perPage);
    }

    public function getAverageRatingByDriverId(string $driverPrincipalId): float {
        $avg = DriverRating::where("driver_principal_id", $driverPrincipalId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByDriverId(string $driverPrincipalId): int {
        return DriverRating::where("driver_principal_id", $driverPrincipalId)->count();
    }
}
