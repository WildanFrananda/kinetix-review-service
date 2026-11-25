<?php

declare(strict_types=1);

namespace App\Lab;

use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CourierRatingsSeed {
    public function __construct(private readonly DriverRatingRepositoryInterface $ratings) {}

    /**
     * @param  list<int>  $stars
     * @return list<int>
     */
    public function write(string $courierPrincipalId, int $count, array $stars): array {
        $written = array_map(fn (int $index): int => $stars[$index % count($stars)], range(0, $count - 1));

        DB::transaction(function () use ($courierPrincipalId, $written): void {
            foreach ($written as $rating) {
                $this->ratings->create([
                    "order_id" => Str::lower((string) Str::uuid()),
                    "customer_principal_id" => Str::lower((string) Str::uuid()),
                    "driver_principal_id" => $courierPrincipalId,
                    "rating" => $rating,
                    "comment" => null,
                ]);
            }
        });

        return $written;
    }
}
