<?php

declare(strict_types=1);

namespace App\Observability;

final class HistogramFamily {
    /** @var array<string, float[]> tallies per bucket, in bucket order, excluding +Inf */
    private array $tallies = [];

    /** @var array<string, float> */
    private array $sums = [];

    /** @var array<string, float> */
    private array $counts = [];

    /** @var array<string, string[]> */
    private array $labelValues = [];

    /**
     * @param string[] $labelNames
     * @param float[] $buckets upper bounds in ascending order; +Inf is implied and never listed
     */
    public function __construct(
        private readonly string $name,
        private readonly string $help,
        private readonly array $labelNames,
        private readonly array $buckets
    ) {}

    /**
     * Render the family at zero for a label combination that has been observed nothing, so the
     * metric is present on a worker that has not served a request yet. See CounterFamily.
     *
     * @param string[] $labelValues
     */
    public function initialise(array $labelValues): void {
        $key = PrometheusText::key($labelValues);

        if (! array_key_exists($key, $this->counts)) {
            $this->tallies[$key] = array_fill(0, count($this->buckets), 0.0);
            $this->sums[$key] = 0.0;
            $this->counts[$key] = 0.0;
            $this->labelValues[$key] = $labelValues;
        }
    }

    /**
     * @param string[] $labelValues
     */
    public function observe(float $value, array $labelValues): void {
        $this->initialise($labelValues);

        $key = PrometheusText::key($labelValues);

        foreach ($this->buckets as $index => $upperBound) {
            if ($value <= $upperBound) {
                $this->tallies[$key][$index] += 1.0;

                break;
            }
        }

        $this->sums[$key] += $value;
        $this->counts[$key] += 1.0;
    }

    public function render(): string {
        $lines = [
            "# HELP {$this->name} {$this->help}",
            "# TYPE {$this->name} histogram",
        ];

        $keys = array_keys($this->counts);
        sort($keys);

        foreach ($keys as $key) {
            $labelValues = $this->labelValues[$key];
            $cumulative = 0.0;

            foreach ($this->buckets as $index => $upperBound) {
                $cumulative += $this->tallies[$key][$index];
                $labels = PrometheusText::labels(
                    [...$this->labelNames, "le"],
                    [...$labelValues, PrometheusText::value($upperBound)]
                );
                $lines[] = "{$this->name}_bucket{$labels} " . PrometheusText::value($cumulative);
            }

            $infinite = PrometheusText::labels(
                [...$this->labelNames, "le"],
                [...$labelValues, "+Inf"]
            );
            $lines[] = "{$this->name}_bucket{$infinite} " . PrometheusText::value($this->counts[$key]);

            $labels = PrometheusText::labels($this->labelNames, $labelValues);
            $lines[] = "{$this->name}_sum{$labels} " . PrometheusText::value($this->sums[$key]);
            $lines[] = "{$this->name}_count{$labels} " . PrometheusText::value($this->counts[$key]);
        }

        return implode("\n", $lines) . "\n";
    }
}
