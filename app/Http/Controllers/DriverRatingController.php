<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DriverRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Middleware\AuthenticateIdentityToken;
use InvalidArgumentException;

class DriverRatingController extends Controller {
    public function __construct(
        private readonly DriverRatingService $service
    ) {}

    public function store(Request $request): JsonResponse {
        $caller = AuthenticateIdentityToken::caller($request);

        $validated = $request->validate([
            "order_id" => ["required", "string"],
            "driver_id" => ["required", "integer"],
            "rating" => ["required", "integer", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000"],
        ]);

        try {
            $rating = $this->service->createRating($caller->userId, $validated);

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
