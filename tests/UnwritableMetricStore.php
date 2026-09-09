<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Observability\MetricStoreInterface;
use App\Observability\MetricStoreUnavailableException;

final class UnwritableMetricStore implements MetricStoreInterface {
    public int $commits = 0;

    public function add(string $key, float $delta): void {
        //
    }

    public function commit(): void {
        $this->commits++;

        throw new MetricStoreUnavailableException("no space left on device");
    }

    /**
     * @return array<string, float>
     */
    public function read(): array {
        return [];
    }
}
