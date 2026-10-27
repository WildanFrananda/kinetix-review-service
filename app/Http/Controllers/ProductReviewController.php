<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateIdentityToken;
use App\Observability\RequestId;
use App\Services\ProductReviewService;
use App\Validation\WithoutNulCharacter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProductReviewController extends Controller {
    public function __construct(
        private readonly ProductReviewService $service
    ) {}

    public function store(Request $request): JsonResponse {
        $caller = AuthenticateIdentityToken::caller($request);

        $validated = $request->validate([
            "order_id" => ["required", "string", "uuid"],
            "product_id" => ["required", "string", new WithoutNulCharacter],
            "rating" => ["required", "integer:strict", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000", new WithoutNulCharacter],
        ]);

        try {
            $review = $this->service->createReview($caller, $validated);

            return response()->json($review, 201);
        } catch (InvalidArgumentException $ex) {
            return response()->json(
                [
                    "error" => "UNPROCESSABLE_ENTITY",
                    "message" => $ex->getMessage(),
                    "traceId" => RequestId::current() ?? "-",
                ],
                422
            );
        }
    }

    public function getProductReviews(Request $request, string $productId): JsonResponse {
        $summary = $this->service->getProductReviewSummary(
            $productId,
            $this->listingPage($request, "product_id", $productId)
        );

        return response()->json($summary);
    }

    public function getMerchantReviews(Request $request, string $merchantPrincipalId): JsonResponse {
        $summary = $this->service->getMerchantReviewSummary(
            $merchantPrincipalId,
            $this->listingPage($request, "merchant_principal_id", $merchantPrincipalId)
        );

        return response()->json($summary);
    }
}
