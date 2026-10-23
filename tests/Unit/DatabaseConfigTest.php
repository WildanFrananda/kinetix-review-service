<?php

declare(strict_types=1);

function databaseConfigUnder(array $variables): array {
    $saved = [];
    foreach ($variables as $name => $value) {
        $saved[$name] = [
            array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            getenv($name),
        ];
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        if ($value !== null) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }

    try {
        return require config_path("database.php");
    } finally {
        foreach ($saved as $name => [$server, $env, $process]) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($process !== false) {
                putenv("{$name}={$process}");
            }
        }
    }
}

it("refuses to load without DB_CONNECTION, naming it, instead of choosing SQLite", function (?string $value) {
    expect(fn () => databaseConfigUnder(["DB_CONNECTION" => $value]))
        ->toThrow(RuntimeException::class, "DB_CONNECTION is required and has no default. Set it in the environment.");
})->with([
    "unset" => [null],
    "empty" => [""],
]);

it("refuses to load the Postgres connection without a setting it needs, naming it", function (
    string $variable,
    ?string $value
) {
    expect(fn () => databaseConfigUnder(["DB_CONNECTION" => "pgsql", $variable => $value]))
        ->toThrow(RuntimeException::class, "{$variable} is required and has no default. Set it in the environment.");
})->with([
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
])->with([
    "unset" => [null],
    "empty" => [""],
]);

it("takes the Postgres connection from the environment alone", function () {
    $config = databaseConfigUnder([
        "DB_CONNECTION" => "pgsql",
        "DB_HOST" => "kinetix-review-db.test",
        "DB_PORT" => "6543",
        "DB_DATABASE" => "kinetix_review_config_test",
        "DB_USERNAME" => "kinetix_review_config_app",
        "DB_PASSWORD" => "config-test-password",
    ]);

    expect($config["default"])->toBe("pgsql")
        ->and($config["connections"]["pgsql"])->toMatchArray([
            "host" => "kinetix-review-db.test",
            "port" => "6543",
            "database" => "kinetix_review_config_test",
            "username" => "kinetix_review_config_app",
            "password" => "config-test-password",
        ]);
});

it("loads with no database settings but a connection's name, as the image build's package discovery does", function () {
    $config = databaseConfigUnder([
        "DB_CONNECTION" => "build-time-placeholder",
        "DB_HOST" => null,
        "DB_PORT" => null,
        "DB_DATABASE" => null,
        "DB_USERNAME" => null,
        "DB_PASSWORD" => null,
    ]);

    expect($config["default"])->toBe("build-time-placeholder")
        ->and($config["connections"]["pgsql"]["host"])->toBeNull();
});
