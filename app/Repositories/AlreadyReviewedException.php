<?php

declare(strict_types=1);

namespace App\Repositories;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class AlreadyReviewedException extends ConflictHttpException {
    private function __construct(
        string $message,
        public readonly ?string $reviewId,
        Throwable $previous
    ) {
        parent::__construct($message, $previous);
    }

    public static function product(?string $reviewId, Throwable $previous): self {
        return new self(
            "this product from this order has already been reviewed. Nothing was saved.",
            $reviewId,
            $previous
        );
    }

    public static function driver(?string $reviewId, Throwable $previous): self {
        return new self(
            "the courier of this order has already been rated. Nothing was saved.",
            $reviewId,
            $previous
        );
    }
}
