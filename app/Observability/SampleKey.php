<?php

declare(strict_types=1);

namespace App\Observability;

final class SampleKey {
    private const SEPARATOR = "\x1f";

    /**
     * @param string[] $labelValues
     */
    public static function encode(string $name, string $kind, array $labelValues): string {
        $parts = [$name, $kind];

        foreach ($labelValues as $value) {
            $parts[] = str_replace(self::SEPARATOR, "", $value);
        }

        return implode(self::SEPARATOR, $parts);
    }

    /**
     * @return array{kind: string, labels: string[]}|null
     */
    public static function decode(string $key, string $name): ?array {
        $parts = explode(self::SEPARATOR, $key);

        if (count($parts) < 2 || $parts[0] !== $name) {
            return null;
        }

        return ["kind" => $parts[1], "labels" => array_slice($parts, 2)];
    }
}
