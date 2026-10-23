<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EloquentDriverRatingRepository implements DriverRatingRepositoryInterface {
    public function create(array $data): DriverRating {
        try {
            return DB::transaction(fn (): DriverRating => DriverRating::create($data));
        } catch (UniqueConstraintViolationException $ex) {
            throw AlreadyReviewedException::driver($this->authorsRatingId($data), $ex);
        }
    }

    public function getPaginatedByDriverId(string $driverPrincipalId, int $perPage = 15): LengthAwarePaginator {
        return DriverRating::where("driver_principal_id", $driverPrincipalId)
            ->latest()
            ->orderByDesc("id")
            ->paginate($perPage);
    }

    public function getAverageRatingByDriverId(string $driverPrincipalId): float {
        $avg = DriverRating::where("driver_principal_id", $driverPrincipalId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByDriverId(string $driverPrincipalId): int {
        return DriverRating::where("driver_principal_id", $driverPrincipalId)->count();
    }

    private function authorsRatingId(array $data): ?string {
        $id = DriverRating::where("order_id", $data["order_id"])
            ->where("driver_principal_id", $data["driver_principal_id"])
            ->where("customer_principal_id", $data["customer_principal_id"])
            ->value("id");

        return $id === null ? null : (string) $id;
    }
}
