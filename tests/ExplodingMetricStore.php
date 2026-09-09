<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Observability\MetricStoreInterface;
use App\Observability\MetricStoreUnavailableException;

final class ExplodingMetricStore implements MetricStoreInterface {
    public function add(string $key, float $delta): void {
        //
    }

    public function commit(): void {
        //
    }

    /**
     * @return array<string, float>
     */
    public function read(): array {
        throw new MetricStoreUnavailableException("the metric store is broken");
    }
}
