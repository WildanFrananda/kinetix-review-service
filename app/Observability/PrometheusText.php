<?php

declare(strict_types=1);

namespace App\Observability;

final class PrometheusText {
    public const CONTENT_TYPE = "text/plain; version=0.0.4; charset=utf-8";

    /**
     * @param string[] $names
     * @param string[] $values
     */
    public static function labels(array $names, array $values): string {
        if ($names === []) {
            return "";
        }

        $pairs = [];
        foreach ($names as $index => $name) {
            $pairs[] = $name . '="' . self::escape((string) ($values[$index] ?? "")) . '"';
        }

        return "{" . implode(",", $pairs) . "}";
    }

    public static function escape(string $value): string {
        return str_replace(["\\", "\n", "\""], ["\\\\", "\\n", "\\\""], $value);
    }

    public static function help(string $help): string {
        return str_replace(["\\", "\n"], ["\\\\", "\\n"], $help);
    }

    public static function value(float $value): string {
        if (is_nan($value)) {
            return "NaN";
        }

        if (is_infinite($value)) {
            return $value > 0.0 ? "+Inf" : "-Inf";
        }

        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        $formatted = rtrim(rtrim(number_format($value, 9, ".", ""), "0"), ".");

        return $formatted === "" || $formatted === "-" ? "0" : $formatted;
    }

    /**
     * @param string[] $labelValues
     */
    public static function key(array $labelValues): string {
        return implode("\x1f", $labelValues);
    }
}
