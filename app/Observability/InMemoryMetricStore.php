<?php

declare(strict_types=1);

namespace App\Observability;

use App\Contracts\Observability\MetricStoreInterface;

final class InMemoryMetricStore implements MetricStoreInterface {
    /** @var array<string, float> */
    private array $values = [];

    public function add(string $key, float $delta): void {
        $this->values[$key] = ($this->values[$key] ?? 0.0) + $delta;
    }

    public function commit(): void {
        //
    }

    /**
     * @return array<string, float>
     */
    public function read(): array {
        return $this->values;
    }
}
