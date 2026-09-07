<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ProductReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Middleware\AuthenticateIdentityToken;
use InvalidArgumentException;

class ProductReviewController extends Controller {
    public function __construct(
        private readonly ProductReviewService $service
    ) {}

    public function store(Request $request): JsonResponse {
        $caller = AuthenticateIdentityToken::caller($request);

        $validated = $request->validate([
            "order_id" => ["required", "string"],
            "product_id" => ["required", "string"],
            "merchant_principal_id" => ["nullable", "string", "max:64"],
            "rating" => ["required", "integer", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000"],
        ]);

        try {
            $review = $this->service->createReview($caller, $validated);

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

    public function getMerchantReviews(string $merchantPrincipalId): JsonResponse {
        $summary = $this->service->getMerchantReviewSummary($merchantPrincipalId);

        return response()->json($summary);
    }
}
