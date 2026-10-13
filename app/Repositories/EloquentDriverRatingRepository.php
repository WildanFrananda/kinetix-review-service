<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;

class EloquentDriverRatingRepository implements DriverRatingRepositoryInterface {
    public function create(array $data): DriverRating {
        try {
            return DriverRating::create($data);
        } catch (UniqueConstraintViolationException $ex) {
            throw AlreadyReviewedException::driver($ex);
        }
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
