<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\ProductReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProductReviewRepository implements ProductReviewRepositoryInterface {
    public function create(array $data): ProductReview {
        return ProductReview::create($data);
    }

    public function getPaginatedByProductId(string $productId, int $perPage = 15): LengthAwarePaginator {
        return ProductReview::where("product_id", $productId)
            ->latest()
            ->paginate($perPage);
    }

    public function getAverageRatingByProductId(string $productId): float {
        $avg = ProductReview::where("product_id", $productId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByProductId(string $productId): int {
        return ProductReview::where("product_id", $productId)->count();
    }

    public function getPaginatedByMerchantId(int $merchantId, int $perPage = 15): LengthAwarePaginator {
        return ProductReview::where("merchant_id", $merchantId)
            ->latest()
            ->paginate($perPage);
    }

    public function getAverageRatingByMerchantId(int $merchantId): float {
        $avg = ProductReview::where("merchant_id", $merchantId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByMerchantId(int $merchantId): int {
        return ProductReview::where("merchant_id", $merchantId)->count();
    }
}
