<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const MIGRATED_ORDER = "4b7d2e9a-1c3f-4a56-9e8d-0f2a4c6e8b1d";

function orderIdMigration(): Migration {
    return require database_path("migrations/2026_10_27_000001_order_ids_are_canonical_uuids.php");
}

function orderIdTypeOf(string $table): string {
    return DB::selectOne(
        "SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = 'order_id'",
        [$table]
    )->data_type;
}

function reviewSpelled(string $orderId, string $productId = "TSHIRT-BLK-M"): string {
    $id = Str::uuid()->toString();
    DB::table("product_reviews")->insert([
        "id" => $id,
        "order_id" => $orderId,
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "product_id" => $productId,
        "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3",
        "rating" => 4,
        "created_at" => now(),
        "updated_at" => now(),
    ]);

    return $id;
}

function ratingSpelled(string $orderId): string {
    $id = Str::uuid()->toString();
    DB::table("driver_ratings")->insert([
        "id" => $id,
        "order_id" => $orderId,
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 5,
        "created_at" => now(),
        "updated_at" => now(),
    ]);

    return $id;
}

function refusalOf(Migration $migration): string {
    try {
        $migration->up();
    } catch (RuntimeException $ex) {
        return $ex->getMessage();
    }

    throw new LogicException("the migration was expected to refuse");
}

beforeEach(function () {
    orderIdMigration()->down();
});

it("stores the order id as a UUID, so Postgres keeps one spelling of it", function () {
    orderIdMigration()->up();

    reviewSpelled(strtoupper(MIGRATED_ORDER));

    expect(orderIdTypeOf("product_reviews"))->toBe("uuid")
        ->and(orderIdTypeOf("driver_ratings"))->toBe("uuid")
        ->and(DB::table("product_reviews")->value("order_id"))->toBe(MIGRATED_ORDER);
});

it("rewrites every spelling order accepts to the canonical form", function () {
    $spellings = [
        "upper case" => strtoupper(MIGRATED_ORDER),
        "braces" => "{" . MIGRATED_ORDER . "}",
        "parentheses" => "(" . strtoupper(MIGRATED_ORDER) . ")",
        "no hyphens" => str_replace("-", "", strtoupper(MIGRATED_ORDER)),
        "canonical" => MIGRATED_ORDER,
    ];
    $reviews = [];
    foreach ($spellings as $spelling => $orderId) {
        $reviews[$spelling] = reviewSpelled($orderId, "SKU-{$spelling}");
    }
    $rating = ratingSpelled("{" . strtoupper(MIGRATED_ORDER) . "}");

    orderIdMigration()->up();

    foreach ($reviews as $id) {
        expect(DB::table("product_reviews")->where("id", $id)->value("order_id"))->toBe(MIGRATED_ORDER);
    }
    expect(DB::table("driver_ratings")->where("id", $rating)->value("order_id"))->toBe(MIGRATED_ORDER)
        ->and(orderIdTypeOf("product_reviews"))->toBe("uuid")
        ->and(orderIdTypeOf("driver_ratings"))->toBe("uuid")
        ->and(DB::table("review_evidence")->where("review_id", $rating)->value("order_id"))
        ->toBe("{" . strtoupper(MIGRATED_ORDER) . "}");
});

it("refuses, changing nothing and naming no order, when two reviews would become one", function () {
    $first = reviewSpelled(MIGRATED_ORDER);
    $second = reviewSpelled(strtoupper(MIGRATED_ORDER));
    $third = reviewSpelled("{" . MIGRATED_ORDER . "}");
    ratingSpelled(MIGRATED_ORDER);
    ratingSpelled(str_replace("-", "", MIGRATED_ORDER));

    $refusal = refusalOf(orderIdMigration());

    expect($refusal)->toContain("1 product review group(s) holding 3 row(s)")
        ->and($refusal)->toContain("1 driver rating group(s) holding 2 row(s)")
        ->and($refusal)->toContain("Nothing was changed")
        ->and(strtolower($refusal))->not->toContain(substr(MIGRATED_ORDER, -12))
        ->and(orderIdTypeOf("product_reviews"))->toBe("character varying")
        ->and(orderIdTypeOf("driver_ratings"))->toBe("character varying")
        ->and(DB::table("product_reviews")->where("id", $first)->value("order_id"))->toBe(MIGRATED_ORDER)
        ->and(DB::table("product_reviews")->where("id", $second)->value("order_id"))->toBe(strtoupper(MIGRATED_ORDER))
        ->and(DB::table("product_reviews")->where("id", $third)->value("order_id"))->toBe("{" . MIGRATED_ORDER . "}");
});

it("refuses, changing nothing and naming no order, an order id order could not have read", function () {
    reviewSpelled("ORD-2026-0001");
    $unchanged = reviewSpelled(strtoupper(MIGRATED_ORDER), "MUG-WHT");
    ratingSpelled("{" . MIGRATED_ORDER);

    $refusal = refusalOf(orderIdMigration());

    expect($refusal)->toContain("1 product review(s) and 1 driver rating(s)")
        ->and($refusal)->toContain("Nothing was changed")
        ->and($refusal)->not->toContain("ORD-2026-0001")
        ->and(orderIdTypeOf("product_reviews"))->toBe("character varying")
        ->and(DB::table("product_reviews")->where("id", $unchanged)->value("order_id"))
        ->toBe(strtoupper(MIGRATED_ORDER));
});
