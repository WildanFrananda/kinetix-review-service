<?php

declare(strict_types=1);

namespace App\Evidence;

final readonly class ReviewEvidencePage {
    public function __construct(
        public array $records,
        public LogPosition $next,
        public bool $hasMore,
    ) {}
}
