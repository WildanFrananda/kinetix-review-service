<?php

declare(strict_types=1);

use App\Resilience\DatabaseOutage;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

function driverError(string $sqlState, string $message): PDOException {
    $error = new PDOException($message);
    $error->errorInfo = [$sqlState, 7, $message];

    return $error;
}

function failedQuery(PDOException $driverError, array $bindings = []): QueryException {
    return new QueryException("pgsql", "select * from product_reviews where product_id = ?", $bindings, $driverError);
}

it("reads a database that could not be reached as an outage", function (Throwable $failure) {
    expect((new DatabaseOutage)->causedBy($failure))->toBeTrue();
})->with([
    "a refused connection, as the connector throws it" => fn () => driverError(
        "08006",
        "SQLSTATE[08006] [7] connection to server at \"10.0.0.5\", port 5432 failed: Connection refused"
    ),
    "a refused connection, wrapped by the query that needed it" => fn () => failedQuery(
        driverError(
            "08006",
            "SQLSTATE[08006] [7] connection to server at \"10.0.0.5\", port 5432 failed: timeout expired"
        )
    ),
    "a connection the client could not establish" => fn () => failedQuery(
        driverError("08001", "SQLSTATE[08001] unable to connect")
    ),
    "a session the administrator ended" => fn () => failedQuery(
        driverError(
            "57P01",
            "SQLSTATE[57P01]: Admin shutdown: 7 FATAL: terminating connection due to administrator command"
        )
    ),
    "a server that crashed" => fn () => failedQuery(
        driverError("57P02", "SQLSTATE[57P02]: Crash shutdown: 7 FATAL: terminating connection because of crash")
    ),
    "a server still starting up" => fn () => failedQuery(
        driverError("57P03", "SQLSTATE[57P03]: Cannot connect now: 7 FATAL: the database system is starting up")
    ),
    "a session the administrator ended, as pdo_pgsql reports it" => fn () => failedQuery(
        driverError(
            "HY000",
            "SQLSTATE[HY000]: General error: 7 FATAL:  terminating connection due to administrator command\n"
                . "server closed the connection unexpectedly"
        )
    ),
    "a session the driver lost" => fn () => failedQuery(
        driverError("HY000", "SQLSTATE[HY000]: General error: 7 no connection to the server")
    ),
    "a session the server closed" => fn () => failedQuery(
        driverError("HY000", "SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly")
    ),
    "a lost connection with no reconnector" => fn () => new LostConnectionException(
        "Lost connection and no reconnector available."
    ),
]);

it("does not read a query the database refused on its merits as an outage", function (Throwable $failure) {
    expect((new DatabaseOutage)->causedBy($failure))->toBeFalse();
})->with([
    "a unique violation" => fn () => failedQuery(
        driverError("23505", "SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value")
    ),
    "a table that is not there" => fn () => failedQuery(
        driverError("42P01", "SQLSTATE[42P01]: Undefined table: 7 ERROR: relation \"x\" does not exist")
    ),
    "a refusal whose text quotes a client's value about a lost connection" => fn () => failedQuery(
        driverError("22P02", "SQLSTATE[22P02]: Invalid text representation: 7 ERROR: invalid input: \"went away\""),
        ["went away"]
    ),
    "a refusal whose bindings mention a refused connection" => fn () => failedQuery(
        driverError("23505", "SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value"),
        ["failed: Connection refused"]
    ),
    "a general driver error that is not about the connection" => fn () => failedQuery(
        driverError("HY000", "SQLSTATE[HY000]: General error: 7 could not determine data type of parameter")
    ),
    "a failure that never reached the database" => fn () => new RuntimeException("failed: Connection refused"),
]);

it("logs the driver's own message as the cause, not the query or its bindings", function () {
    $failure = failedQuery(
        driverError("08006", "SQLSTATE[08006] [7] connection to server at \"10.0.0.5\", port 5432 failed"),
        ["a comment a buyer wrote"]
    );

    expect(DatabaseOutage::reason($failure))
        ->toBe("PDOException: SQLSTATE[08006] [7] connection to server at \"10.0.0.5\", port 5432 failed");
});
