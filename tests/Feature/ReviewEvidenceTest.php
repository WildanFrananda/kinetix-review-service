<?php

declare(strict_types=1);

use App\Clients\OrderDetails;
use App\Contracts\Clients\OrderClientInterface;
use App\Models\DriverRating;
use App\Models\ProductReview;
use App\Security\TokenVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\FakeOrderClient;
use Tests\IdentityTokens;

const EVIDENCE_BUYER = "11111111-2222-3333-4444-555555555555";
const EVIDENCE_MERCHANT = "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3";
const EVIDENCE_COURIER = "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914";

beforeEach(function () {
    $orders = new FakeOrderClient;
    $orders->addOrder(
        new OrderDetails(
            "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b",
            EVIDENCE_BUYER,
            EVIDENCE_MERCHANT,
            EVIDENCE_COURIER,
            true,
            ["TSHIRT-BLK-M"]
        )
    );
    $this->app->instance(OrderClientInterface::class, $orders);
    $this->app->instance(TokenVerifier::class, IdentityTokens::verifier());
});

function evidenceRows(): array {
    return DB::select(
        "SELECT review_id::text AS review_id, kind, order_id, subject_id, author_principal_id, "
            . "merchant_principal_id, rating, encode(comment_sha256, 'hex') AS comment_sha256, "
            . "extract(epoch FROM created_at)::bigint AS created_at FROM review_evidence ORDER BY sequence"
    );
}

it("logs a product review exactly as it was written", function () {
    $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/products", [
            "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b",
            "product_id" => "TSHIRT-BLK-M",
            "rating" => 4,
            "comment" => "Kainnya adem — ukuran pas",
        ])
        ->assertStatus(201);

    $review = ProductReview::sole();
    $rows = evidenceRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->review_id)->toBe($review->id)
        ->and($rows[0]->kind)->toBe("PRODUCT")
        ->and($rows[0]->order_id)->toBe("5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b")
        ->and($rows[0]->subject_id)->toBe("TSHIRT-BLK-M")
        ->and($rows[0]->author_principal_id)->toBe(EVIDENCE_BUYER)
        ->and($rows[0]->merchant_principal_id)->toBe(EVIDENCE_MERCHANT)
        ->and((int) $rows[0]->rating)->toBe(4)
        ->and($rows[0]->comment_sha256)->toBe(hash("sha256", "Kainnya adem — ukuran pas"))
        ->and((int) $rows[0]->created_at)->toBe($review->created_at->getTimestamp());
});

it("logs a driver rating with the courier as its subject and no merchant", function () {
    $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", [
            "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b",
            "rating" => 5,
        ])
        ->assertStatus(201);

    $rating = DriverRating::sole();
    $rows = evidenceRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->review_id)->toBe($rating->id)
        ->and($rows[0]->kind)->toBe("DRIVER")
        ->and($rows[0]->subject_id)->toBe(EVIDENCE_COURIER)
        ->and($rows[0]->author_principal_id)->toBe(EVIDENCE_BUYER)
        ->and($rows[0]->merchant_principal_id)->toBeNull()
        ->and($rows[0]->comment_sha256)->toBeNull();
});

it("logs nothing for a review that was refused", function () {
    $review = [
        "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b",
        "product_id" => "TSHIRT-BLK-M",
        "rating" => 3,
    ];

    $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/products", $review)->assertStatus(201);
    $this->withHeaders(IdentityTokens::bearer())->postJson("/api/v1/reviews/products", $review)->assertStatus(409);

    expect(evidenceRows())->toHaveCount(1);
});

it("logs a review written straight into the table, so no code path can skip the log", function () {
    DB::table("product_reviews")->insert([
        "id" => "0b8f6a52-4d3e-4c1a-9f27-5e6d7c8b9a01",
        "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0c",
        "customer_principal_id" => EVIDENCE_BUYER,
        "product_id" => "MUG-WHT",
        "merchant_principal_id" => null,
        "rating" => 2,
        "comment" => null,
        "created_at" => "2026-10-16 08:00:00",
        "updated_at" => "2026-10-16 08:00:00",
    ]);

    $rows = evidenceRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->review_id)->toBe("0b8f6a52-4d3e-4c1a-9f27-5e6d7c8b9a01")
        ->and((int) $rows[0]->created_at)->toBe(strtotime("2026-10-16 08:00:00 UTC"));
});

it("refuses evidence written straight into the log rather than by a review", function () {
    expect(fn () => DB::transaction(fn () => DB::table("review_evidence")->insert([
        "review_id" => "1f2e3d4c-5b6a-4978-8695-a4b3c2d1e0f9",
        "kind" => "PRODUCT",
        "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0d",
        "subject_id" => "TSHIRT-BLK-M",
        "author_principal_id" => EVIDENCE_BUYER,
        "rating" => 5,
        "created_at" => "2026-10-17 00:00:00+00",
    ])))->toThrow(QueryException::class, "written by the review tables' triggers only");

    expect(evidenceRows())->toBe([]);
});

it("refuses to change or remove what the log holds", function (string $statement) {
    $this->withHeaders(IdentityTokens::bearer())
        ->postJson("/api/v1/reviews/drivers", ["order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0b", "rating" => 5])
        ->assertStatus(201);

    expect(fn () => DB::transaction(fn () => DB::statement($statement)))
        ->toThrow(QueryException::class, "review_evidence is append-only");

    expect(evidenceRows())->toHaveCount(1);
})->with([
    "update" => "UPDATE review_evidence SET rating = 1",
    "delete" => "DELETE FROM review_evidence",
    "truncate" => "TRUNCATE review_evidence",
]);
