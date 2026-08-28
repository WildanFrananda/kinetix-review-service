<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DriverRatingRepositoryInterface {
    public function create(array $data): DriverRating;

    public function getPaginatedByDriverId(int $driverId, int $perPage = 15): LengthAwarePaginator;

    public function getAverageRatingByDriverId(int $driverId): float;

    public function getTotalCountByDriverId(int $driverId): int;
}
