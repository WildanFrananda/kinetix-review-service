<?php

declare(strict_types=1);

use App\Logging\FailureLogContext;
use Illuminate\Database\QueryException;

function refusalFromTheDriver(string|int $sqlState, string $message): PDOException {
    $error = new PDOException($message);
    $error->errorInfo = is_string($sqlState) ? [$sqlState, 7, $message] : null;

    return $error;
}

function refusedInsertOf(PDOException $driverError): QueryException {
    return new QueryException(
        "pgsql",
        "insert into \"product_reviews\" (\"comment\", \"customer_principal_id\") values (?, ?)",
        ["a comment a buyer wrote", "11111111-2222-3333-4444-555555555555"],
        $driverError
    );
}

it("describes a refused query by its SQLSTATE and its SQL, never its bindings or the driver's message", function () {
    $failure = refusedInsertOf(
        refusalFromTheDriver(
            "23514",
            "SQLSTATE[23514]: Check violation: 7 ERROR: new row violates check constraint\n"
                . "DETAIL: Failing row contains (a comment a buyer wrote, 11111111-2222-3333-4444-555555555555)."
        )
    );

    $context = FailureLogContext::of($failure);

    expect($context)->toBe([
        "exception" => QueryException::class,
        "sqlstate" => "23514",
        "sql" => "insert into \"product_reviews\" (\"comment\", \"customer_principal_id\") values (?, ?)",
    ])
        ->and(json_encode($context))->not->toContain("a comment a buyer wrote")
        ->not->toContain("11111111-2222-3333-4444-555555555555");
});

it("describes a driver error with no query by its SQLSTATE alone", function () {
    $failure = refusalFromTheDriver("22P02", "SQLSTATE[22P02]: Invalid text representation: 7 ERROR: \"not-a-uuid\"");

    expect(FailureLogContext::of($failure))->toBe([
        "exception" => PDOException::class,
        "sqlstate" => "22P02",
    ]);
});

it("leaves the SQLSTATE out when the driver reported none", function () {
    $failure = refusedInsertOf(refusalFromTheDriver(7, "could not send data to server"));

    expect(FailureLogContext::of($failure))->toBe([
        "exception" => QueryException::class,
        "sql" => "insert into \"product_reviews\" (\"comment\", \"customer_principal_id\") values (?, ?)",
    ]);
});

it("drops the message of an exception that wraps a refused query, since it may repeat the query's", function () {
    $query = refusedInsertOf(refusalFromTheDriver("23514", "SQLSTATE[23514]: Check violation"));
    $failure = new RuntimeException("saving failed: " . $query->getMessage(), 0, $query);

    expect(FailureLogContext::of($failure))->toBe([
        "exception" => RuntimeException::class,
        "sqlstate" => "23514",
        "sql" => "insert into \"product_reviews\" (\"comment\", \"customer_principal_id\") values (?, ?)",
    ]);
});

it("keeps the message of a failure the database had no part in", function () {
    expect(FailureLogContext::of(new LogicException("a bug in review's own code")))->toBe([
        "exception" => LogicException::class,
        "reason" => "a bug in review's own code",
    ]);
});
