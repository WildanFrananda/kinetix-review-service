<?php

declare(strict_types=1);

namespace App\Observability;

final class VersionLabel {
    public const MAX_LENGTH = 16;

    public const UNKNOWN = "unknown";

    public static function of(string $version): string {
        $allowed = preg_replace('/[^A-Za-z0-9._+-]/', "", $version) ?? "";

        $shortened = substr($allowed, 0, self::MAX_LENGTH);

        return $shortened === "" ? self::UNKNOWN : $shortened;
    }
}
