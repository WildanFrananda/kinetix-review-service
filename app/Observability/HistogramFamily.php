<?php

declare(strict_types=1);

namespace App\Observability;

use App\Contracts\Observability\MetricStoreInterface;

final class HistogramFamily {
    private const SUM = "sum";

    private const COUNT = "count";

    /**
     * @param string[] $labelNames
     * @param float[] $buckets upper bounds in ascending order; +Inf is implied and never listed
     */
    public function __construct(
        private readonly MetricStoreInterface $store,
        private readonly string $name,
        private readonly string $help,
        private readonly array $labelNames,
        private readonly array $buckets
    ) {}

    /**
     * @param string[] $labelValues
     */
    public function initialise(array $labelValues): void {
        foreach (array_keys($this->buckets) as $index) {
            $this->store->add($this->key(self::bucket($index), $labelValues), 0.0);
        }

        $this->store->add($this->key(self::SUM, $labelValues), 0.0);
        $this->store->add($this->key(self::COUNT, $labelValues), 0.0);
    }

    /**
     * @param string[] $labelValues
     */
    public function observe(float $value, array $labelValues): void {
        $this->initialise($labelValues);

        foreach ($this->buckets as $index => $upperBound) {
            if ($value <= $upperBound) {
                $this->store->add($this->key(self::bucket($index), $labelValues), 1.0);

                break;
            }
        }

        $this->store->add($this->key(self::SUM, $labelValues), $value);
        $this->store->add($this->key(self::COUNT, $labelValues), 1.0);
    }

    /**
     * @param array<string, float> $samples
     */
    public function render(array $samples): string {
        $lines = [
            "# HELP {$this->name} " . PrometheusText::help($this->help),
            "# TYPE {$this->name} histogram",
        ];

        /** @var array<string, string[]> $series */
        $series = [];

        foreach (array_keys($samples) as $key) {
            $sample = SampleKey::decode((string) $key, $this->name);

            if ($sample !== null) {
                $series[PrometheusText::key($sample["labels"])] = $sample["labels"];
            }
        }

        ksort($series);

        foreach ($series as $labelValues) {
            $cumulative = 0.0;

            foreach ($this->buckets as $index => $upperBound) {
                $cumulative += $samples[$this->key(self::bucket($index), $labelValues)] ?? 0.0;
                $labels = PrometheusText::labels(
                    [...$this->labelNames, "le"],
                    [...$labelValues, PrometheusText::value($upperBound)]
                );
                $lines[] = "{$this->name}_bucket{$labels} " . PrometheusText::value($cumulative);
            }

            $count = $samples[$this->key(self::COUNT, $labelValues)] ?? 0.0;
            $sum = $samples[$this->key(self::SUM, $labelValues)] ?? 0.0;

            $infinite = PrometheusText::labels(
                [...$this->labelNames, "le"],
                [...$labelValues, "+Inf"]
            );
            $lines[] = "{$this->name}_bucket{$infinite} " . PrometheusText::value($count);

            $labels = PrometheusText::labels($this->labelNames, $labelValues);
            $lines[] = "{$this->name}_sum{$labels} " . PrometheusText::value($sum);
            $lines[] = "{$this->name}_count{$labels} " . PrometheusText::value($count);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string[] $labelValues
     */
    private function key(string $kind, array $labelValues): string {
        return SampleKey::encode($this->name, $kind, $labelValues);
    }

    private static function bucket(int $index): string {
        return "bucket:{$index}";
    }
}
