<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    private string $metricStoreFile = "";

    protected function setUp(): void {
        parent::setUp();

        $this->metricStoreFile = storage_path(
            "framework/testing/metrics-" . bin2hex(random_bytes(8)) . ".json"
        );

        config(["metrics.store_file" => $this->metricStoreFile]);
    }

    protected function tearDown(): void {
        if ($this->metricStoreFile !== "" && is_file($this->metricStoreFile)) {
            unlink($this->metricStoreFile);
        }

        parent::tearDown();
    }
}
