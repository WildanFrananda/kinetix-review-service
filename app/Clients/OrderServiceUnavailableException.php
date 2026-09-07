<?php

declare(strict_types=1);

namespace App\Clients;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class OrderServiceUnavailableException extends ServiceUnavailableHttpException {
    public static function breakerOpen(int $retryAfterSeconds): self {
        return new self(
            $retryAfterSeconds,
            "the order service is failing and calls to it are paused, so this review could not be "
                . "checked against your order. Nothing was saved."
        );
    }

    public static function transport(): self {
        return new self(
            null,
            "the order service could not be reached, so this review could not be checked against "
                . "your order. Nothing was saved."
        );
    }
}
