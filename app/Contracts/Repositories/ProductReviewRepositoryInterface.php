<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\ProductReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductReviewRepositoryInterface {
    public function create(array $data): ProductReview;

    public function getPaginatedByProductId(string $productId, int $perPage = 15): LengthAwarePaginator;

    public function getAverageRatingByProductId(string $productId): float;

    public function getTotalCountByProductId(string $productId): int;

    public function getPaginatedByMerchantId(string $merchantPrincipalId, int $perPage = 15): LengthAwarePaginator;

    public function getAverageRatingByMerchantId(string $merchantPrincipalId): float;

    public function getTotalCountByMerchantId(string $merchantPrincipalId): int;
}
