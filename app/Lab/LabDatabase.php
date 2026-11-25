<?php

declare(strict_types=1);

namespace App\Lab;

final class LabDatabase {
    private const SUFFIX = "_dev";

    public static function holds(string $database): bool {
        return str_ends_with($database, self::SUFFIX);
    }
}
