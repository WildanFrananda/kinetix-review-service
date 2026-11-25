<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lab\CourierRatingsSeed;
use App\Lab\LabDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedLabCourierRatings extends Command {
    private const MAX_RATINGS = 1_000;

    protected $signature = "lab:seed-courier-ratings
        {courier : The courier's principal id, as identity issued it}
        {--count=40 : How many ratings to write}
        {--stars=5,5,4,5,4 : The ratings to write, taken in turn}";

    protected $description = "Write buyers' ratings of one courier into the lab's database, for seal's courier credential";

    public function handle(CourierRatingsSeed $seed): int {
        $courier = trim((string) $this->argument("courier"));
        $count = filter_var($this->option("count"), FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1, "max_range" => self::MAX_RATINGS],
        ]);
        $stars = self::starsOf((string) $this->option("stars"));

        if ($courier === "") {
            return $this->refuse("the courier's principal id is empty");
        }

        if ($count === false) {
            return $this->refuse(sprintf("--count must be a whole number from 1 to %d", self::MAX_RATINGS));
        }

        if ($stars === null) {
            return $this->refuse("--stars must be whole numbers from 1 to 5, separated by commas");
        }

        $database = (string) DB::connection()->getDatabaseName();

        if (! LabDatabase::holds($database)) {
            return $this->refuse(sprintf("%s is not a lab database; the seed writes only to one ending in _dev", $database));
        }

        $written = $seed->write($courier, $count, $stars);

        $this->info(sprintf(
            "seeded %d ratings of %s, averaging %.2f",
            count($written),
            $courier,
            array_sum($written) / count($written)
        ));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int {
        $this->error("the lab seed refused: " . $reason);

        return self::FAILURE;
    }

    /**
     * @return list<int>|null
     */
    private static function starsOf(string $option): ?array {
        $stars = [];

        foreach (explode(",", $option) as $part) {
            $star = filter_var(trim($part), FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 5]]);

            if ($star === false) {
                return null;
            }

            $stars[] = $star;
        }

        return $stars;
    }
}
