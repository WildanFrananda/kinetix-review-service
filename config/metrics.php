<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Shared Metric Store
    |--------------------------------------------------------------------------
    |
    | Where App\Observability\FileMetricStore keeps the counts every FrankenPHP worker in this
    | container adds to and every scrape reads back. It has to be one path that all of them can
    | write to: a per-worker path would put the service back where it started, reporting one
    | worker's slice of the traffic as though it were the whole.
    |
    | Under storage/ because that is the directory the image already makes writable for the
    | kinetix user, and NOT under a volume: counters that outlive the process would carry a dead
    | container's traffic into a new one. A restart starting from zero is what Prometheus expects.
    |
    | The suite points this at a file of its own per test, so a test run never reads counts left
    | by the run before it.
    |
    */

    "store_file" => env("METRICS_STORE_FILE", storage_path("framework/metrics/metrics.json")),

];
