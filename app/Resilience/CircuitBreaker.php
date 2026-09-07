<?php

declare(strict_types=1);

namespace App\Resilience;

final class CircuitBreaker {
    private int $failures = 0;

    private float $openedAt = 0.0;

    private bool $probeInFlight = false;

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 30.0
    ) {}

    public function allows(): bool {
        if ($this->openedAt === 0.0) {
            return true;
        }

        if ($this->probeInFlight) {
            return false;
        }

        if (microtime(true) - $this->openedAt < $this->cooldownSeconds) {
            return false;
        }

        $this->probeInFlight = true;

        return true;
    }

    public function recordSuccess(): void {
        $this->failures = 0;
        $this->openedAt = 0.0;
        $this->probeInFlight = false;
    }

    public function recordFailure(): void {
        if ($this->probeInFlight) {
            $this->probeInFlight = false;
            $this->openedAt = microtime(true);

            return;
        }

        $this->failures++;

        if ($this->failures >= $this->failureThreshold) {
            $this->openedAt = microtime(true);
        }
    }

    public function retryAfterSeconds(): int {
        if ($this->openedAt === 0.0) {
            return 0;
        }

        $remaining = $this->cooldownSeconds - (microtime(true) - $this->openedAt);

        return (int) max(1, ceil($remaining));
    }

    public function name(): string {
        return $this->name;
    }
}
