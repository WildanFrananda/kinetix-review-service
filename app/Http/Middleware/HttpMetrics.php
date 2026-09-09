<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class HttpMetrics {
    public function __construct(private readonly MetricsRegistry $metrics) {}

    public function handle(Request $request, Closure $next): Response {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $this->metrics->recordHttpRequest(
            $request->getMethod(),
            self::routeLabel($request),
            $response->getStatusCode(),
            microtime(true) - $startedAt
        );

        return $response;
    }

    private static function routeLabel(Request $request): string {
        $route = $request->route();

        if (! $route instanceof Route) {
            return MetricsRegistry::UNMATCHED_ROUTE;
        }

        $uri = $route->uri();

        return $uri === "/" ? "/" : "/" . ltrim($uri, "/");
    }
}
