<?php

declare(strict_types=1);

use App\Logging\JsonLineFormatter;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;

function logRecord(array $context = [], Level $level = Level::Info, string $message = "review saved"): LogRecord {
    return new LogRecord(
        datetime: new JsonSerializableDateTimeImmutable(true, new DateTimeZone("UTC")),
        channel: "kinetix-review-service",
        level: $level,
        message: $message,
        context: $context,
    );
}

it("writes one line, one JSON object, with the five fields the estate's other services write", function () {
    $line = (new JsonLineFormatter)->format(logRecord(["request_id" => "kinetix-trace-1757-42"]));

    expect(substr_count($line, "\n"))->toBe(1);
    expect($line)->toEndWith("\n");

    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect(array_slice(array_keys($decoded), 0, 5))
        ->toBe(["timestamp", "level", "logger", "message", "request_id"]);
    expect($decoded["timestamp"])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/');
    expect($decoded["level"])->toBe("INFO");
    expect($decoded["logger"])->toBe("kinetix-review-service");
    expect($decoded["message"])->toBe("review saved");
    expect($decoded["request_id"])->toBe("kinetix-trace-1757-42");
});

it("leaves the correlation id greppable as a bare substring", function (string $id) {
    $line = (new JsonLineFormatter)->format(logRecord(["request_id" => $id]));

    expect(str_contains($line, $id))->toBeTrue();
})->with([
    "the gate's own shape" => "kinetix-trace-1757458800-99",
    "Kong's uuid#counter" => "7f3a91c2-4d5e-4b8a-9c1d-2e6f8a0b3c4d#7",
    "a bare uuid" => "7f3a91c2-4d5e-4b8a-9c1d-2e6f8a0b3c4d",
]);

it("says so rather than guessing when a record carries no correlation id", function () {
    $decoded = json_decode(
        (new JsonLineFormatter)->format(logRecord()),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($decoded["request_id"])->toBe("-");
});

it("keeps the structured context the call site attached", function () {
    $line = (new JsonLineFormatter)->format(logRecord([
        "request_id" => "kinetix-trace-1",
        "breaker" => "order-service",
        "grpc_code" => 14,
    ]));

    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded["breaker"])->toBe("order-service");
    expect($decoded["grpc_code"])->toBe(14);
});

it("does not let a context key redefine one of the five", function () {
    $decoded = json_decode(
        (new JsonLineFormatter)->format(logRecord([
            "request_id" => "kinetix-trace-1",
            "level" => "DEBUG",
            "message" => "something else",
            "logger" => "somewhere else",
        ])),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($decoded["level"])->toBe("INFO");
    expect($decoded["message"])->toBe("review saved");
    expect($decoded["logger"])->toBe("kinetix-review-service");
});

it("keeps a multi-line message, and a stack trace, on one physical line", function () {
    $line = (new JsonLineFormatter)->format(logRecord(
        ["exception" => new RuntimeException("first\nsecond")],
        Level::Error,
        "first\nsecond"
    ));

    expect(substr_count($line, "\n"))->toBe(1);

    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded["message"])->toBe("first\nsecond");
    expect($decoded["exception"]["class"])->toBe("RuntimeException");
});

it("reports the level a reader greps for, not Monolog's number", function () {
    $decoded = json_decode(
        (new JsonLineFormatter)->format(logRecord([], Level::Warning)),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($decoded["level"])->toBe("WARNING");
});

it("keeps a stack trace short enough that the line survives Octane's pipe, and says what it cut", function () {
    $deep = null;
    $make = function (int $depth) use (&$make): void {
        if ($depth > 0) {
            $make($depth - 1);

            return;
        }

        throw new RuntimeException("deep");
    };

    try {
        $make(40);
    } catch (RuntimeException $exception) {
        $deep = $exception;
    }

    $line = (new JsonLineFormatter)->format(logRecord(["exception" => $deep], Level::Error));
    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect(count($decoded["exception"]["trace"]))->toBe(20);
    expect($decoded["exception"]["trace_frames_omitted"])->toBeGreaterThan(0);
    expect(strlen($line))->toBeLessThan(16384);
});

it("keeps one line one object when the large thing is the context and not the trace", function () {
    $line = (new JsonLineFormatter)->format(logRecord([
        "request_id" => "kinetix-trace-1757458800-99",
        "payload" => str_repeat("a", 200_000),
    ]));

    expect(substr_count($line, "\n"))->toBe(1);
    expect(strlen($line))->toBeLessThan(16384);

    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded["request_id"])->toBe("kinetix-trace-1757458800-99");
    expect($decoded["message"])->toBe("review saved");
    expect($decoded["truncated"])->toBeTrue();
    expect($decoded["original_line_bytes"])->toBeGreaterThan(JsonLineFormatter::MAX_LINE_BYTES);
});

it("holds the cap even when every unbounded field at once is enormous", function () {
    $line = (new JsonLineFormatter)->format(logRecord(
        [
            "request_id" => str_repeat("\u{1f600}", 20_000),
            "payload" => str_repeat("\"\\\n", 40_000),
        ],
        Level::Error,
        str_repeat("\u{00e9}", 60_000)
    ));

    expect(substr_count($line, "\n"))->toBe(1);
    expect(strlen($line))->toBeLessThanOrEqual(JsonLineFormatter::MAX_LINE_BYTES + 1);

    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded["truncated"])->toBeTrue();
    expect($decoded["level"])->toBe("ERROR");
});
