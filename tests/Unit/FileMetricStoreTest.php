<?php

declare(strict_types=1);

use App\Observability\FileMetricStore;
use App\Observability\MetricStoreUnavailableException;

function metricStorePath(): string {
    return storage_path("framework/testing/store-" . bin2hex(random_bytes(8)) . ".json");
}

it("has nothing in it before anything is written, rather than refusing to be read", function () {
    $path = metricStorePath();

    try {
        expect((new FileMetricStore($path))->read())->toBe([]);
    } finally {
        @unlink($path);
    }
});

it("shows one instance's writes to another instance on the same path", function () {
    $path = metricStorePath();

    try {
        $writer = new FileMetricStore($path);
        $writer->add("kinetix_http_requests_total\x1f\x1fGET", 1.0);
        $writer->add("kinetix_http_requests_total\x1f\x1fGET", 1.0);
        $writer->commit();

        expect((new FileMetricStore($path))->read())
            ->toBe(["kinetix_http_requests_total\x1f\x1fGET" => 2.0]);
    } finally {
        @unlink($path);
    }
});

it("adds to what is already there instead of replacing it", function () {
    $path = metricStorePath();

    try {
        $first = new FileMetricStore($path);
        $first->add("a", 3.0);
        $first->commit();

        $second = new FileMetricStore($path);
        $second->add("a", 4.0);
        $second->add("b", 1.0);
        $second->commit();

        expect((new FileMetricStore($path))->read())->toBe(["a" => 7.0, "b" => 1.0]);
    } finally {
        @unlink($path);
    }
});

it("keeps nothing buffered after a commit, so a scrape between two requests is not double counted", function () {
    $path = metricStorePath();

    try {
        $store = new FileMetricStore($path);
        $store->add("a", 1.0);
        $store->commit();
        $store->commit();
        $store->commit();

        expect((new FileMetricStore($path))->read())->toBe(["a" => 1.0]);
    } finally {
        @unlink($path);
    }
});

it("refuses a half-written file rather than reading it as a service that served nothing", function (string $contents) {
    $path = metricStorePath();

    try {
        file_put_contents($path, $contents);

        expect(fn () => (new FileMetricStore($path))->read())
            ->toThrow(MetricStoreUnavailableException::class);
    } finally {
        @unlink($path);
    }
})->with([
    "truncated JSON" => '{"kinetix_http_requests_totalGET": 12',
    "a list where a map belongs" => "[1,2,3]",
    "a sample that is not a number" => '{"a": "twelve"}',
    "something that was never JSON" => "corrupted",
]);

it("says so when it cannot write, and does not carry the lost writes into the next commit", function () {
    $blocker = storage_path("framework/testing/blocker-" . bin2hex(random_bytes(8)));

    try {
        file_put_contents($blocker, "not a directory");

        $store = new FileMetricStore($blocker . "/metrics.json");
        $store->add("a", 1.0);

        expect(fn () => $store->commit())->toThrow(MetricStoreUnavailableException::class);

        $store->commit();
    } finally {
        @unlink($blocker);
    }
});
