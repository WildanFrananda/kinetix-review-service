<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller {
    public function health(): JsonResponse {
        return response()->json([
            "status" => "ok",
            "service" => "kinetix-review-service",
            "framework" => "Laravel 13 (PHP 8.5.7)",
            "timestamp" => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse {
        try {
            DB::connection()->getPdo();
            DB::select("SELECT 1");
        } catch (Throwable $exception) {
            return response()->json([
                "status" => "unavailable",
                "database" => "unreachable",
            ], 503);
        }

        return response()->json([
            "status" => "ok",
            "database" => "reachable",
        ]);
    }
}
