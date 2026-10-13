<?php

declare(strict_types=1);

namespace App\Repositories;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class AlreadyReviewedException extends ConflictHttpException {
    public static function product(Throwable $previous): self {
        return new self(
            "this product from this order has already been reviewed. Nothing was saved.",
            $previous
        );
    }

    public static function driver(Throwable $previous): self {
        return new self(
            "the courier of this order has already been rated. Nothing was saved.",
            $previous
        );
    }
}
