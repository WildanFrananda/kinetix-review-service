<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ProductReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProductReviewController extends Controller {
    public function __construct(
        private readonly ProductReviewService $service
    ) {}

    public function store(Request $request): JsonResponse {
        $customerIdHeader = $request->header("X-User-Id");
        if (! $customerIdHeader || ! is_numeric($customerIdHeader) || (int) $customerIdHeader <= 0) {
            return response()->json([
                "error" => "UNAUTHORIZED",
                "message" => "X-User-Id header is required",
            ], 401);
        }

        $validated = $request->validate([
            "order_id" => ["required", "string"],
            "product_id" => ["required", "string"],
            "merchant_id" => ["nullable", "integer"],
            "rating" => ["required", "integer", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000"],
        ]);

        try {
            $review = $this->service->createReview((int) $customerIdHeader, $validated);

            return response()->json($review, 201);
        } catch (InvalidArgumentException $ex) {
            return response()->json([
                "error" => "UNPROCESSABLE_ENTITY",
                "message" => $ex->getMessage(),
            ], 422);
        }
    }

    public function getProductReviews(string $productId): JsonResponse {
        $summary = $this->service->getProductReviewSummary($productId);

        return response()->json($summary);
    }

    public function getMerchantReviews(int $merchantId): JsonResponse {
        $summary = $this->service->getMerchantReviewSummary($merchantId);

        return response()->json($summary);
    }
}
