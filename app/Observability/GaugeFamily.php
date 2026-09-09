<?php

declare(strict_types=1);

namespace App\Observability;

final class GaugeFamily {
    /** @var array<string, float> */
    private array $values = [];

    /** @var array<string, string[]> */
    private array $labelValues = [];

    /**
     * @param string[] $labelNames
     */
    public function __construct(
        private readonly string $name,
        private readonly string $help,
        private readonly array $labelNames
    ) {}

    /**
     * @param string[] $labelValues
     */
    public function set(float $value, array $labelValues = []): void {
        $key = PrometheusText::key($labelValues);

        $this->values[$key] = $value;
        $this->labelValues[$key] = $labelValues;
    }

    public function render(): string {
        $lines = [
            "# HELP {$this->name} {$this->help}",
            "# TYPE {$this->name} gauge",
        ];

        $keys = array_keys($this->values);
        sort($keys);

        foreach ($keys as $key) {
            $labels = PrometheusText::labels($this->labelNames, $this->labelValues[$key]);
            $lines[] = $this->name . $labels . " " . PrometheusText::value($this->values[$key]);
        }

        return implode("\n", $lines) . "\n";
    }
}
