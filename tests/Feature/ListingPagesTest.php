<?php

declare(strict_types=1);

use App\Contracts\Clients\OrderClientInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\FakeOrderClient;

beforeEach(function () {
    $this->app->instance(OrderClientInterface::class, new FakeOrderClient);
});

function reviewsWrittenInOneSecond(string $table, array $columns, int $count): array {
    $writtenAt = Carbon::parse("2026-10-22 03:15:00");
    $ids = [];

    for ($index = 0; $index < $count; $index++) {
        $id = Str::uuid()->toString();
        $ids[] = $id;

        DB::table($table)->insert([
            ...$columns,
            "id" => $id,
            "order_id" => Str::uuid()->toString(),
            "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
            "rating" => 1 + $index % 5,
            "created_at" => $writtenAt,
            "updated_at" => $writtenAt,
        ]);
    }

    return $ids;
}

it("lists each review once across pages, newest first, then by id", function (string $table, array $row, string $path) {
    $ids = reviewsWrittenInOneSecond($table, $row, 40);

    $listed = [];
    for ($page = 1; $page <= 3; $page++) {
        $response = $this->getJson("{$path}?page={$page}");

        $response->assertStatus(200)->assertJsonPath("last_page", 3);
        $listed = [...$listed, ...array_column($response->json("data"), "id")];
    }

    rsort($ids, SORT_STRING);
    expect($listed)->toBe($ids);
})->with([
    "a product's reviews" => [
        "product_reviews",
        ["product_id" => "TSHIRT-BLK-M", "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3"],
        "/api/v1/reviews/products/TSHIRT-BLK-M",
    ],
    "a merchant's reviews" => [
        "product_reviews",
        ["product_id" => "TSHIRT-BLK-M", "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3"],
        "/api/v1/reviews/merchants/3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
    ],
    "a courier's ratings" => [
        "driver_ratings",
        ["driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914"],
        "/api/v1/reviews/drivers/b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
    ],
]);

it("still lists a later review before an earlier one, whatever their ids", function () {
    DB::table("product_reviews")->insert([
        [
            "id" => "00000000-0000-4000-8000-000000000001",
            "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b10",
            "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 5,
            "created_at" => Carbon::parse("2026-10-22 03:15:01"),
            "updated_at" => Carbon::parse("2026-10-22 03:15:01"),
        ],
        [
            "id" => "ffffffff-ffff-4fff-bfff-ffffffffffff",
            "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b11",
            "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 1,
            "created_at" => Carbon::parse("2026-10-22 03:15:00"),
            "updated_at" => Carbon::parse("2026-10-22 03:15:00"),
        ],
    ]);

    $response = $this->getJson("/api/v1/reviews/products/TSHIRT-BLK-M");

    expect(array_column($response->json("data"), "id"))->toBe([
        "00000000-0000-4000-8000-000000000001",
        "ffffffff-ffff-4fff-bfff-ffffffffffff",
    ]);
});

dataset("listings", [
    "a product's reviews" => [
        "product_reviews",
        ["product_id" => "TSHIRT-BLK-M", "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3"],
        "/api/v1/reviews/products/TSHIRT-BLK-M",
    ],
    "a merchant's reviews" => [
        "product_reviews",
        ["product_id" => "TSHIRT-BLK-M", "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3"],
        "/api/v1/reviews/merchants/3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
    ],
    "a courier's ratings" => [
        "driver_ratings",
        ["driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914"],
        "/api/v1/reviews/drivers/b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
    ],
]);

it("refuses a page outside 1 to 10000 with 422, naming the limit", function (
    string $table,
    array $row,
    string $path,
    string $page,
    string $message
) {
    reviewsWrittenInOneSecond($table, $row, 1);

    $response = $this->withHeader("X-Request-Id", "req-page-bound")->getJson("{$path}?page={$page}");

    $response->assertStatus(422)->assertExactJson([
        "error" => "VALIDATION_FAILED",
        "message" => $message,
        "errors" => ["page" => [$message]],
        "traceId" => "req-page-bound",
    ]);
})->with("listings")->with([
    "one past the last page served" => ["10001", "The page field must not be greater than 10000."],
    "one whose offset PHP cannot hold" => ["614891469123651722", "The page field must not be greater than 10000."],
    "the largest integer PHP has" => ["9223372036854775807", "The page field must not be greater than 10000."],
    "zero" => ["0", "The page field must be at least 1."],
    "a word" => ["last", "The page field must be an integer."],
]);

it("serves the last page it allows, empty past the reviews there are", function (
    string $table,
    array $row,
    string $path
) {
    reviewsWrittenInOneSecond($table, $row, 1);

    $response = $this->getJson("{$path}?page=10000");

    $response->assertStatus(200)
        ->assertJsonPath("current_page", 10000)
        ->assertJsonPath("last_page", 1)
        ->assertJsonPath("data", []);
})->with("listings");
