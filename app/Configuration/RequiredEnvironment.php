<?php

declare(strict_types=1);

namespace App\Configuration;

use RuntimeException;

final class RequiredEnvironment {
    public static function value(string $name): string {
        $value = env($name);
        if (! is_string($value) || $value === "") {
            throw new RuntimeException("{$name} is required and has no default. Set it in the environment.");
        }

        return $value;
    }

    public static function forConnection(string $connection, string $name): mixed {
        if (env("DB_CONNECTION") !== $connection) {
            return env($name);
        }

        return self::value($name);
    }
}
