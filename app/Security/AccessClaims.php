<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

final class AccessClaims {
    private function __construct(
        public readonly string $principalId,
        public readonly int $userId,
        public readonly string $email,
        public readonly string $role
    ) {}

    public static function fromPayload(array $payload): self {
        return new self(
            self::text($payload, "sub"),
            self::number($payload, "uid"),
            self::text($payload, "email"),
            self::text($payload, "role")
        );
    }

    private static function text(array $payload, string $name): string {
        $value = $payload[$name] ?? null;
        if (! is_string($value) || $value === "") {
            throw new InvalidArgumentException("claim '{$name}' is missing or not a string");
        }

        return $value;
    }

    private static function number(array $payload, string $name): int {
        $value = $payload[$name] ?? null;
        if (! is_int($value)) {
            throw new InvalidArgumentException("claim '{$name}' is missing or not an integer");
        }

        return $value;
    }
}
