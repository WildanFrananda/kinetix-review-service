<?php

declare(strict_types=1);

use App\Observability\CounterFamily;
use App\Observability\GaugeFamily;
use App\Observability\HistogramFamily;
use App\Observability\InMemoryMetricStore;
use App\Observability\PrometheusText;

it("escapes the two characters that would end a HELP comment early", function () {
    expect(PrometheusText::help("a \\ and a\nnewline"))->toBe("a \\\\ and a\\nnewline");
});

it("leaves a quote in a HELP alone, because a HELP is not a quoted string", function () {
    expect(PrometheusText::help('the "order" peer'))->toBe('the "order" peer');
});

it("keeps a HELP line on one line whichever family writes it", function () {
    $store = new InMemoryMetricStore;
    $help = "a backslash \\ and a\nnewline";

    $bodies = [
        (new CounterFamily($store, "kinetix_test_total", $help, []))->render([]),
        (new HistogramFamily($store, "kinetix_test_seconds", $help, [], [1.0]))->render([]),
        (new GaugeFamily("kinetix_test_info", $help, []))->render(),
    ];

    foreach ($bodies as $body) {
        expect(substr_count($body, "\n"))->toBe(2);
        expect($body)->toContain("a backslash \\\\ and a\\nnewline");
    }
});
