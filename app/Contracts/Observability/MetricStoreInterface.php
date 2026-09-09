<?php

declare(strict_types=1);

namespace App\Contracts\Observability;

use App\Observability\MetricStoreUnavailableException;

interface MetricStoreInterface {
    public function add(string $key, float $delta): void;

    /**
     * @throws MetricStoreUnavailableException
     */
    public function commit(): void;

    /**
     * @return array<string, float>
     *
     * @throws MetricStoreUnavailableException
     */
    public function read(): array;
}
