<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\ProductReview;
use App\Security\AccessClaims;
use InvalidArgumentException;

class ProductReviewService {
    public function __construct(
        private readonly ProductReviewRepositoryInterface $repository,
        private readonly OrderClientInterface $orderClient
    ) {}

    public function createReview(AccessClaims $caller, array $data): ProductReview {
        $order = $this->orderClient->getOrderDetails($data["order_id"]);
        if ($order === null) {
            throw new InvalidArgumentException("Order not found.");
        }

        if ($order->customerPrincipalId !== $caller->principalId) {
            throw new InvalidArgumentException("Order does not belong to this customer.");
        }

        if (! $order->delivered) {
            throw new InvalidArgumentException("Reviews are only allowed for DELIVERED or COMPLETED orders.");
        }

        if (! $order->contains($data["product_id"])) {
            throw new InvalidArgumentException("Product was not purchased in this order.");
        }

        return $this->repository->create([
            "order_id" => $data["order_id"],
            "customer_principal_id" => $caller->principalId,
            "product_id" => $data["product_id"],
            "merchant_principal_id" => $order->merchantPrincipalId !== "" ? $order->merchantPrincipalId : null,
            "rating" => $data["rating"],
            "comment" => $data["comment"] ?? null,
        ]);
    }

    public function getProductReviewSummary(string $productId): array {
        $reviews = $this->repository->getPaginatedByProductId($productId);
        $avgRating = $this->repository->getAverageRatingByProductId($productId);
        $totalCount = $this->repository->getTotalCountByProductId($productId);

        return [
            "product_id" => $productId,
            "average_rating" => $avgRating,
            "total_reviews" => $totalCount,
            "data" => $reviews->items(),
            "current_page" => $reviews->currentPage(),
            "last_page" => $reviews->lastPage(),
        ];
    }

    public function getMerchantReviewSummary(string $merchantPrincipalId): array {
        $reviews = $this->repository->getPaginatedByMerchantId($merchantPrincipalId);
        $avgRating = $this->repository->getAverageRatingByMerchantId($merchantPrincipalId);
        $totalCount = $this->repository->getTotalCountByMerchantId($merchantPrincipalId);

        return [
            "merchant_principal_id" => $merchantPrincipalId,
            "average_rating" => $avgRating,
            "total_reviews" => $totalCount,
            "data" => $reviews->items(),
            "current_page" => $reviews->currentPage(),
            "last_page" => $reviews->lastPage(),
        ];
    }
}
