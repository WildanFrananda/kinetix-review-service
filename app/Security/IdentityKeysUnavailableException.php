<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class IdentityKeysUnavailableException extends ServiceUnavailableHttpException {
    public static function unreachable(): self {
        return new self(
            null,
            "identity's signing keys could not be fetched, so the token on this request could not be "
                . "checked. Nothing was saved."
        );
    }
}
