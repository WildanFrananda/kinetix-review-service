<?php

declare(strict_types=1);

use App\Resilience\CircuitBreaker;

it("stays closed while failures are below the threshold", function () {
    $breaker = new CircuitBreaker("test", 3, 30.0);

    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->allows())->toBeTrue();
});

it("opens on the threshold failure and refuses for the whole cooldown", function () {
    $breaker = new CircuitBreaker("test", 3, 30.0);

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->allows())->toBeFalse();
    expect($breaker->retryAfterSeconds())->toBe(30);
});

it("forgets earlier failures once a call succeeds", function () {
    $breaker = new CircuitBreaker("test", 3, 30.0);

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordSuccess();
    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->allows())->toBeTrue();
});

it("admits exactly one probe once the cooldown has elapsed", function () {
    $breaker = new CircuitBreaker("test", 1, 0.05);
    $breaker->recordFailure();

    usleep(60_000);

    expect($breaker->allows())->toBeTrue();
    expect($breaker->allows())->toBeFalse();
});

it("closes again when the probe succeeds", function () {
    $breaker = new CircuitBreaker("test", 1, 0.05);
    $breaker->recordFailure();
    usleep(60_000);

    expect($breaker->allows())->toBeTrue();
    $breaker->recordSuccess();

    expect($breaker->allows())->toBeTrue();
    expect($breaker->retryAfterSeconds())->toBe(0);
});

it("re-opens a fresh window when the probe fails", function () {
    $breaker = new CircuitBreaker("test", 1, 0.05);
    $breaker->recordFailure();
    usleep(60_000);

    expect($breaker->allows())->toBeTrue();
    $breaker->recordFailure();

    expect($breaker->allows())->toBeFalse();

    usleep(60_000);

    expect($breaker->allows())->toBeTrue();
});
