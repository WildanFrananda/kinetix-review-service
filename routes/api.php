<?php

declare(strict_types=1);

use App\Http\Controllers\DriverRatingController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Middleware\AuthenticateIdentityToken;
use Illuminate\Support\Facades\Route;

Route::get("/health", [HealthController::class, "health"]);
Route::get("/health/ready", [HealthController::class, "ready"]);

Route::prefix("v1")->group(function () {
    Route::middleware(AuthenticateIdentityToken::class)->group(function () {
        Route::post("/reviews/products", [ProductReviewController::class, "store"]);
        Route::post("/reviews/drivers", [DriverRatingController::class, "store"]);
    });

    Route::get("/reviews/products/{productId}", [ProductReviewController::class, "getProductReviews"]);
    Route::get("/reviews/merchants/{merchantId}", [ProductReviewController::class, "getMerchantReviews"]);
    Route::get("/reviews/drivers/{driverId}", [DriverRatingController::class, "getDriverRatings"]);
});
