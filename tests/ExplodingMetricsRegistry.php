<?php

declare(strict_types=1);

namespace Tests;

use App\Observability\MetricsRegistry;
use RuntimeException;

class ExplodingMetricsRegistry extends MetricsRegistry {
    public function __construct() {
        parent::__construct("0.0.0-test");
    }

    public function render(): string {
        throw new RuntimeException("the registry is broken");
    }
}
