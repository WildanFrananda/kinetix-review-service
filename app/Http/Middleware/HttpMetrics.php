<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Observability\MetricsRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

final class HttpMetrics {
    public function __construct(
        private readonly MetricsRegistry $metrics,
        private readonly Router $router
    ) {}

    public function handle(Request $request, Closure $next): Response {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $this->metrics->recordHttpRequest(
            $request->getMethod(),
            $this->routeLabel($request),
            $response->getStatusCode(),
            microtime(true) - $startedAt
        );

        return $response;
    }

    private function routeLabel(Request $request): string {
        $route = $request->route();

        if (! $route instanceof Route || ! $this->isRegistered($route)) {
            return MetricsRegistry::UNMATCHED_ROUTE;
        }

        $uri = $route->uri();

        return $uri === "/" ? "/" : "/" . ltrim($uri, "/");
    }

    private function isRegistered(Route $route): bool {
        return in_array($route, $this->router->getRoutes()->getRoutes(), true);
    }
}
