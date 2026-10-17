<?php

declare(strict_types=1);

namespace App\Evidence;

final readonly class ReviewEvidenceRecord {
    public function __construct(
        public LogPosition $position,
        public string $reviewId,
        public string $kind,
        public string $orderId,
        public string $subjectId,
        public string $authorPrincipalId,
        public ?string $merchantPrincipalId,
        public int $rating,
        public ?string $commentSha256,
        public int $createdAt,
    ) {}
}
