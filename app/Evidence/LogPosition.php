<?php

declare(strict_types=1);

namespace App\Evidence;

final readonly class LogPosition {
    public function __construct(
        public int $transactionId,
        public int $sequence,
    ) {}

    public static function start(): self {
        return new self(0, 0);
    }
}
