<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\ProductReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class EloquentProductReviewRepository implements ProductReviewRepositoryInterface {
    public function create(array $data): ProductReview {
        try {
            return DB::transaction(fn (): ProductReview => ProductReview::create($data));
        } catch (UniqueConstraintViolationException $ex) {
            throw AlreadyReviewedException::product($this->authorsReviewId($data), $ex);
        }
    }

    public function getPaginatedByProductId(string $productId, int $page, int $perPage = 15): LengthAwarePaginator {
        return ProductReview::where("product_id", $productId)
            ->latest()
            ->orderByDesc("id")
            ->paginate($perPage, ["*"], "page", $page);
    }

    public function getAverageRatingByProductId(string $productId): float {
        $avg = ProductReview::where("product_id", $productId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByProductId(string $productId): int {
        return ProductReview::where("product_id", $productId)->count();
    }

    public function getPaginatedByMerchantId(
        string $merchantPrincipalId,
        int $page,
        int $perPage = 15
    ): LengthAwarePaginator {
        return ProductReview::where("merchant_principal_id", $merchantPrincipalId)
            ->latest()
            ->orderByDesc("id")
            ->paginate($perPage, ["*"], "page", $page);
    }

    public function getAverageRatingByMerchantId(string $merchantPrincipalId): float {
        $avg = ProductReview::where("merchant_principal_id", $merchantPrincipalId)->avg("rating");

        return round((float) ($avg ?? 0.0), 2);
    }

    public function getTotalCountByMerchantId(string $merchantPrincipalId): int {
        return ProductReview::where("merchant_principal_id", $merchantPrincipalId)->count();
    }

    private function authorsReviewId(array $data): ?string {
        $id = ProductReview::where("order_id", $data["order_id"])
            ->where("product_id", $data["product_id"])
            ->where("customer_principal_id", $data["customer_principal_id"])
            ->value("id");

        return $id === null ? null : (string) $id;
    }
}
