<?php

declare(strict_types=1);

namespace App\Observability;

final class RequestId {
    public const HEADER = "X-Request-Id";

    public const METADATA_KEY = "x-request-id";

    public static function current(): ?string {
        if (!app()->bound("request")) {
            return null;
        }

        $requestId = request()->header(self::HEADER);

        return is_string($requestId) && $requestId !== "" ? $requestId : null;
    }

    public static function metadata(): array {
        $requestId = self::current();

        return $requestId === null ? [] : [self::METADATA_KEY => [$requestId]];
    }
}
