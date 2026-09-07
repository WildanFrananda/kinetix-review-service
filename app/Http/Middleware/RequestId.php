<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\RequestId as CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class RequestId {
    public function handle(Request $request, Closure $next): Response {
        $requestId = CorrelationId::current();

        if ($requestId !== null) {
            Log::withContext(["request_id" => $requestId]);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($requestId !== null) {
            $response->headers->set(CorrelationId::HEADER, $requestId);
        }

        return $response;
    }
}
