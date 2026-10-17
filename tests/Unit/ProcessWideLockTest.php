<?php

declare(strict_types=1);

use App\Concurrency\ProcessWideLock;

beforeEach(function () {
    $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "kinetix-review-lock-" . bin2hex(random_bytes(6));
});

afterEach(function () {
    @unlink($this->path);
});

$takenElsewhere = function (string $path): bool {
    $other = fopen($path, "c");
    $taken = flock($other, LOCK_SH | LOCK_NB);

    if ($taken) {
        flock($other, LOCK_UN);
    }

    fclose($other);

    return $taken;
};

it("returns what the guarded section returns", function () {
    $lock = new ProcessWideLock($this->path);

    expect($lock->hold(fn (): string => "built"))->toBe("built");
});

it("holds the lock against every other open of the file while the section runs", function () use ($takenElsewhere) {
    $lock = new ProcessWideLock($this->path);

    $takenDuringSection = $lock->hold(fn (): bool => $takenElsewhere($this->path));

    expect($takenDuringSection)->toBeFalse();
    expect($takenElsewhere($this->path))->toBeTrue();
});

it("releases the lock when the section throws", function () use ($takenElsewhere) {
    $lock = new ProcessWideLock($this->path);

    expect(fn () => $lock->hold(function (): never {
        throw new RuntimeException("the section failed");
    }))->toThrow(RuntimeException::class, "the section failed");

    expect($takenElsewhere($this->path))->toBeTrue();
});

it("refuses to run the section when the lock file cannot be opened", function () {
    $lock = new ProcessWideLock($this->path . DIRECTORY_SEPARATOR . "missing" . DIRECTORY_SEPARATOR . "grpc.lock");
    $ran = false;

    expect(fn () => $lock->hold(function () use (&$ran): void {
        $ran = true;
    }))->toThrow(RuntimeException::class, "cannot be opened");

    expect($ran)->toBeFalse();
});
