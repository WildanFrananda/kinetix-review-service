<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DriverRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DriverRatingController extends Controller {
    public function __construct(
        private readonly DriverRatingService $service
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
            "driver_id" => ["required", "integer"],
            "rating" => ["required", "integer", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000"],
        ]);

        try {
            $rating = $this->service->createRating((int) $customerIdHeader, $validated);

            return response()->json($rating, 201);
        } catch (InvalidArgumentException $ex) {
            return response()->json([
                "error" => "UNPROCESSABLE_ENTITY",
                "message" => $ex->getMessage(),
            ], 422);
        }
    }

    public function getDriverRatings(int $driverId): JsonResponse {
        $summary = $this->service->getDriverRatingSummary($driverId);

        return response()->json($summary);
    }
}
