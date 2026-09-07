<?php

declare(strict_types=1);

namespace App\Resilience;

final class CircuitBreaker {
    private int $failures = 0;

    private float $openedAt = 0.0;

    private float $probeStartedAt = 0.0;

    public function __construct(
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly float $cooldownSeconds = 30.0
    ) {}

    public function allows(): bool {
        if ($this->openedAt === 0.0) {
            return true;
        }

        $now = microtime(true);

        if ($this->probeStartedAt !== 0.0 && $now - $this->probeStartedAt < $this->cooldownSeconds) {
            return false;
        }

        if ($now - $this->openedAt < $this->cooldownSeconds) {
            return false;
        }

        $this->probeStartedAt = $now;

        return true;
    }

    public function recordSuccess(): void {
        $this->failures = 0;
        $this->openedAt = 0.0;
        $this->probeStartedAt = 0.0;
    }

    public function recordFailure(): void {
        if ($this->probeStartedAt !== 0.0) {
            $this->probeStartedAt = 0.0;
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

        $admitsAgainAt = $this->openedAt + $this->cooldownSeconds;

        if ($this->probeStartedAt !== 0.0) {
            $admitsAgainAt = max($admitsAgainAt, $this->probeStartedAt + $this->cooldownSeconds);
        }

        return (int) max(1, ceil($admitsAgainAt - microtime(true)));
    }

    public function name(): string {
        return $this->name;
    }
}
