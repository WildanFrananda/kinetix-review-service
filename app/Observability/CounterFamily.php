<?php

declare(strict_types=1);

namespace App\Observability;

use App\Contracts\Observability\MetricStoreInterface;

final class CounterFamily {
    private const KIND = "";

    /**
     * @param string[] $labelNames
     */
    public function __construct(
        private readonly MetricStoreInterface $store,
        private readonly string $name,
        private readonly string $help,
        private readonly array $labelNames
    ) {}

    /**
     * @param string[] $labelValues
     */
    public function initialise(array $labelValues): void {
        $this->store->add(SampleKey::encode($this->name, self::KIND, $labelValues), 0.0);
    }

    /**
     * @param string[] $labelValues
     */
    public function increment(array $labelValues): void {
        $this->store->add(SampleKey::encode($this->name, self::KIND, $labelValues), 1.0);
    }

    /**
     * @param array<string, float> $samples
     */
    public function render(array $samples): string {
        $lines = [
            "# HELP {$this->name} " . PrometheusText::help($this->help),
            "# TYPE {$this->name} counter",
        ];

        $mine = [];

        foreach ($samples as $key => $value) {
            $sample = SampleKey::decode((string) $key, $this->name);

            if ($sample !== null) {
                $mine[(string) $key] = $sample["labels"];
            }
        }

        ksort($mine);

        foreach ($mine as $key => $labelValues) {
            $labels = PrometheusText::labels($this->labelNames, $labelValues);
            $lines[] = $this->name . $labels . " " . PrometheusText::value($samples[$key]);
        }

        return implode("\n", $lines) . "\n";
    }
}
