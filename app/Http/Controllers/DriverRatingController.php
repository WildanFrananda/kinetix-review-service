<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateIdentityToken;
use App\Observability\RequestId;
use App\Services\DriverRatingService;
use App\Validation\WithoutNulCharacter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DriverRatingController extends Controller {
    public function __construct(
        private readonly DriverRatingService $service
    ) {}

    public function store(Request $request): JsonResponse {
        $caller = AuthenticateIdentityToken::caller($request);

        $validated = $request->validate([
            "order_id" => ["required", "string", "uuid"],
            "rating" => ["required", "integer:strict", "min:1", "max:5"],
            "comment" => ["nullable", "string", "max:1000", new WithoutNulCharacter],
        ]);

        try {
            $rating = $this->service->createRating($caller, $validated);

            return response()->json($rating, 201);
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

    public function getDriverRatings(Request $request, string $driverPrincipalId): JsonResponse {
        $summary = $this->service->getDriverRatingSummary(
            $driverPrincipalId,
            $this->listingPage($request, "driver_principal_id", $driverPrincipalId)
        );

        return response()->json($summary);
    }
}
