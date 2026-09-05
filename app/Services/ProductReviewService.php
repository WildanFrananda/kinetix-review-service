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
        $customerId = $caller->userId;

        $order = $this->orderClient->getOrderDetails($data["order_id"]);
        if (! $order) {
            throw new InvalidArgumentException("Order not found.");
        }

        $orderCustomerId = (int) ($order["customer_id"] ?? 0);
        if ($orderCustomerId !== $customerId) {
            throw new InvalidArgumentException("Order does not belong to this customer.");
        }

        $status = strtoupper((string) ($order["status"] ?? ""));
        if ($status !== "DELIVERED" && $status !== "COMPLETED") {
            throw new InvalidArgumentException("Reviews are only allowed for DELIVERED or COMPLETED orders.");
        }

        $items = $order["items"] ?? [];
        $purchasedProduct = false;
        foreach ($items as $item) {
            $itemId = (string) ($item["product_id"] ?? "");
            if ($itemId === $data["product_id"]) {
                $purchasedProduct = true;
                break;
            }
        }

        if (! $purchasedProduct) {
            throw new InvalidArgumentException("Product was not purchased in this order.");
        }

        return $this->repository->create([
            "order_id" => $data["order_id"],
            "customer_id" => $customerId,
            "product_id" => $data["product_id"],
            "merchant_id" => $data["merchant_id"] ?? null,
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

    public function getMerchantReviewSummary(int $merchantId): array {
        $reviews = $this->repository->getPaginatedByMerchantId($merchantId);
        $avgRating = $this->repository->getAverageRatingByMerchantId($merchantId);
        $totalCount = $this->repository->getTotalCountByMerchantId($merchantId);

        return [
            "merchant_id" => $merchantId,
            "average_rating" => $avgRating,
            "total_reviews" => $totalCount,
            "data" => $reviews->items(),
            "current_page" => $reviews->currentPage(),
            "last_page" => $reviews->lastPage(),
        ];
    }
}
