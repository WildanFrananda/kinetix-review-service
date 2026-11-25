<?php

declare(strict_types=1);

use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Lab\CourierRatingsSeed;
use App\Lab\LabDatabase;
use App\Models\DriverRating;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

const SEEDED_COURIER = "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914";

it("rates the courier as many times as asked, taking the stars in turn", function () {
    $written = app(CourierRatingsSeed::class)->write(SEEDED_COURIER, 7, [5, 4, 5]);

    expect($written)->toBe([5, 4, 5, 5, 4, 5, 5]);

    $ratings = DriverRating::where("driver_principal_id", SEEDED_COURIER)->get();

    expect($ratings)->toHaveCount(7)
        ->and($ratings->sum("rating"))->toBe(33)
        ->and($ratings->pluck("order_id")->unique())->toHaveCount(7)
        ->and($ratings->pluck("customer_principal_id")->unique())->toHaveCount(7)
        ->and($ratings->whereNotNull("comment"))->toHaveCount(0);
});

it("leaves every seeded rating in the evidence log, as the feed seal reads", function () {
    app(CourierRatingsSeed::class)->write(SEEDED_COURIER, 3, [4]);

    $logged = DB::select(
        "SELECT kind, subject_id, rating FROM review_evidence WHERE subject_id = ? ORDER BY sequence",
        [SEEDED_COURIER]
    );

    expect(array_map(fn (object $row): array => [$row->kind, $row->rating], $logged))
        ->toBe([["DRIVER", 4], ["DRIVER", 4], ["DRIVER", 4]]);
});

it("writes only to a database whose name ends in _dev", function () {
    expect(LabDatabase::holds("kinetix_review_dev"))->toBeTrue()
        ->and(LabDatabase::holds("kinetix_review"))->toBeFalse()
        ->and(LabDatabase::holds("kinetix_review_test"))->toBeFalse();

    $this->artisan("lab:seed-courier-ratings", ["courier" => SEEDED_COURIER])
        ->expectsOutputToContain("kinetix_review_test is not a lab database")
        ->assertFailed();

    expect(DriverRating::count())->toBe(0);
});

it("refuses a count or stars it cannot write", function (array $options, string $reason) {
    $this->artisan("lab:seed-courier-ratings", ["courier" => SEEDED_COURIER, ...$options])
        ->expectsOutputToContain($reason)
        ->assertFailed();

    expect(DriverRating::count())->toBe(0);
})->with([
    "no ratings" => [["--count" => "0"], "--count must be a whole number from 1 to 1000"],
    "too many" => [["--count" => "1001"], "--count must be a whole number from 1 to 1000"],
    "not a number" => [["--count" => "ten"], "--count must be a whole number from 1 to 1000"],
    "a sixth star" => [["--stars" => "5,6"], "--stars must be whole numbers from 1 to 5"],
    "an empty star" => [["--stars" => "5,,4"], "--stars must be whole numbers from 1 to 5"],
    "a half star" => [["--stars" => "4.5"], "--stars must be whole numbers from 1 to 5"],
]);

it("refuses an empty courier", function () {
    $this->artisan("lab:seed-courier-ratings", ["courier" => "  "])
        ->expectsOutputToContain("the courier's principal id is empty")
        ->assertFailed();
});

it("rolls back earlier inserts when a later rating fails inside the transaction", function () {
    $real = app(DriverRatingRepositoryInterface::class);
    $failingOnSecond = new class($real) implements DriverRatingRepositoryInterface {
        private int $calls = 0;

        public function __construct(private readonly DriverRatingRepositoryInterface $inner) {}

        public function create(array $data): DriverRating {
            $this->calls++;
            if ($this->calls === 2) {
                throw new RuntimeException("simulated failure on second rating");
            }

            return $this->inner->create($data);
        }

        public function getPaginatedByDriverId(
            string $driverPrincipalId,
            int $page,
            int $perPage = 15
        ): LengthAwarePaginator {
            return $this->inner->getPaginatedByDriverId($driverPrincipalId, $page, $perPage);
        }

        public function getAverageRatingByDriverId(string $driverPrincipalId): float {
            return $this->inner->getAverageRatingByDriverId($driverPrincipalId);
        }

        public function getTotalCountByDriverId(string $driverPrincipalId): int {
            return $this->inner->getTotalCountByDriverId($driverPrincipalId);
        }
    };

    $seed = new CourierRatingsSeed($failingOnSecond);

    expect(fn () => $seed->write(SEEDED_COURIER, 2, [5, 4]))
        ->toThrow(RuntimeException::class, "simulated failure on second rating");

    expect(DriverRating::where("driver_principal_id", SEEDED_COURIER)->count())->toBe(0);

    $evidenceCount = DB::selectOne(
        "SELECT COUNT(*) AS aggregate FROM review_evidence WHERE subject_id = ?",
        [SEEDED_COURIER]
    );

    expect((int) $evidenceCount->aggregate)->toBe(0);
});
