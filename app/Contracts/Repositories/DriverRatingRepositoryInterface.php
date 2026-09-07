<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DriverRatingRepositoryInterface {
    public function create(array $data): DriverRating;

    public function getPaginatedByDriverId(string $driverPrincipalId, int $perPage = 15): LengthAwarePaginator;

    public function getAverageRatingByDriverId(string $driverPrincipalId): float;

    public function getTotalCountByDriverId(string $driverPrincipalId): int;
}
