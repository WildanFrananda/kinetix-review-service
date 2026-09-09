<?php

declare(strict_types=1);

use App\Console\OctaneDrain;

it("installs itself only for the command that serves", function () {
    expect(OctaneDrain::shouldInstall(["artisan", "octane:start", "--server=frankenphp"]))->toBeTrue();
});

it("leaves every other artisan command to exit the way it always did", function (array $argv) {
    expect(OctaneDrain::shouldInstall($argv))->toBeFalse();
})->with([
    "migrate" => [["artisan", "migrate", "--force"]],
    "a queue worker" => [["artisan", "queue:work"]],
    "no arguments at all" => [[]],
    "a near miss" => [["artisan", "octane:status"]],
]);
