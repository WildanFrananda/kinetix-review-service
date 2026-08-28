<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller {
    public function health(): JsonResponse {
        return response()->json([
            "status" => "ok",
            "service" => "kinetix-review-service",
            "framework" => "Laravel 13 (PHP 8.5.7)",
            "timestamp" => now()->toIso8601String(),
        ]);
    }
}
