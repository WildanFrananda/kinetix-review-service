<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Observability\MetricsRegistry;
use App\Observability\PrometheusText;
use App\Observability\RequestId;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class MetricsController extends Controller {
    public function __construct(private readonly MetricsRegistry $metrics) {}

    public function __invoke(): Response {
        try {
            $body = $this->metrics->render();
        } catch (Throwable $exception) {
            Log::error("could not render the metrics registry", [
                "exception" => $exception::class,
                "message" => $exception->getMessage(),
                "request_id" => RequestId::current() ?? "-",
            ]);

            return new Response(
                "# the metrics registry could not be rendered. This is not a report of zero traffic.\n",
                503,
                ["Content-Type" => PrometheusText::CONTENT_TYPE]
            );
        }

        return new Response($body, 200, ["Content-Type" => PrometheusText::CONTENT_TYPE]);
    }
}
