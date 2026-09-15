<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

final class JsonLineFormatter extends NormalizerFormatter {
    public const REQUEST_ID_FIELD = "request_id";

    public const NO_REQUEST_ID = "-";

    public const MAX_LINE_BYTES = 16384;

    private const RESERVED = ["timestamp", "level", "logger", "message", self::REQUEST_ID_FIELD];

    private const MAX_TRACE_FRAMES = 20;

    private const MAX_MESSAGE_BYTES = 1024;

    private const MAX_REQUEST_ID_BYTES = 256;

    public function __construct() {
        parent::__construct("Y-m-d\TH:i:s.vP");
    }

    public function format(LogRecord $record): string {
        /** @var array<string, mixed> $normalised */
        $normalised = parent::format($record);

        /** @var array<string, mixed> $context */
        $context = is_array($normalised["context"] ?? null) ? $normalised["context"] : [];

        /** @var array<string, mixed> $extra */
        $extra = is_array($normalised["extra"] ?? null) ? $normalised["extra"] : [];

        $requestId = $context[self::REQUEST_ID_FIELD] ?? $extra[self::REQUEST_ID_FIELD] ?? null;

        $line = [
            "timestamp" => $normalised["datetime"] ?? null,
            "level" => strtoupper($record->level->getName()),
            "logger" => $normalised["channel"] ?? "",
            "message" => $normalised["message"] ?? "",
            self::REQUEST_ID_FIELD => is_string($requestId) && $requestId !== ""
                ? $requestId
                : self::NO_REQUEST_ID,
        ];

        foreach ([...$context, ...$extra] as $key => $value) {
            if (! in_array($key, self::RESERVED, true)) {
                $line[$key] = self::bounded($value);
            }
        }

        $encoded = $this->toJson($line, true);

        if (strlen($encoded) <= self::MAX_LINE_BYTES) {
            return $encoded . "\n";
        }

        return $this->toJson([
            "timestamp" => $line["timestamp"],
            "level" => $line["level"],
            "logger" => $line["logger"],
            "message" => self::cut((string) $line["message"], self::MAX_MESSAGE_BYTES),
            self::REQUEST_ID_FIELD => self::cut(
                (string) $line[self::REQUEST_ID_FIELD],
                self::MAX_REQUEST_ID_BYTES
            ),
            "truncated" => true,
            "original_line_bytes" => strlen($encoded),
        ], true) . "\n";
    }

    private static function cut(string $value, int $bytes): string {
        return mb_strcut($value, 0, $bytes, "UTF-8");
    }

    private static function bounded(mixed $value): mixed {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if ($key === "trace" && is_array($item) && count($item) > self::MAX_TRACE_FRAMES) {
                $result["trace"] = array_slice($item, 0, self::MAX_TRACE_FRAMES);
                $result["trace_frames_omitted"] = count($item) - self::MAX_TRACE_FRAMES;

                continue;
            }

            $result[$key] = self::bounded($item);
        }

        return $result;
    }
}
