<?php

declare(strict_types=1);

use App\Contracts\Repositories\ReviewEvidenceRepositoryInterface;
use App\Evidence\LogPosition;
use App\Grpc\ReviewEvidenceService;
use Common\V1\LogCursor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Review\V1\ReviewKind;
use Review\V1\ReviewsSinceRequest;
use Spiral\RoadRunner\GRPC\Context;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

function review(string $connection = "pgsql", ?string $comment = null, string $kind = "PRODUCT"): string {
    $id = (string) Str::uuid();
    $row = [
        "id" => $id,
        "order_id" => (string) Str::uuid(),
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "rating" => 4,
        "comment" => $comment,
        "created_at" => now(),
        "updated_at" => now(),
    ];

    if ($kind === "PRODUCT") {
        DB::connection($connection)->table("product_reviews")->insert(
            $row + ["product_id" => "TSHIRT-BLK-M", "merchant_principal_id" => "3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3"]
        );
    } else {
        DB::connection($connection)->table("driver_ratings")->insert(
            $row + ["driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914"]
        );
    }

    return $id;
}

function reviewIds(array $records): array {
    return array_map(fn ($record) => $record->reviewId, $records);
}

function ask(ReviewsSinceRequest $request) {
    return app(ReviewEvidenceService::class)->ReviewsSince(new Context([]), $request);
}

beforeEach(function () {
    Artisan::call("migrate:fresh");
});

afterEach(function () {
    DB::disconnect("pgsql_concurrent");
    Artisan::call("migrate:fresh");
});

it("serves the log in commit order, a page at a time", function () {
    $written = [review(), review(kind: "DRIVER"), review(), review(kind: "DRIVER"), review()];
    $feed = app(ReviewEvidenceRepositoryInterface::class);

    $first = $feed->since(LogPosition::start(), 2);
    $second = $feed->since($first->next, 2);
    $third = $feed->since($second->next, 2);
    $after = $feed->since($third->next, 2);

    expect([...reviewIds($first->records), ...reviewIds($second->records), ...reviewIds($third->records)])
        ->toBe($written)
        ->and([$first->hasMore, $second->hasMore, $third->hasMore])->toBe([true, true, false])
        ->and($after->records)->toBe([])
        ->and($after->next)->toEqual($third->next);
});

it("serves a review whose transaction commits after a later one's, rather than skipping it", function () {
    config(["database.connections.pgsql_concurrent" => config("database.connections.pgsql")]);
    $holder = DB::connection("pgsql_concurrent");
    $feed = app(ReviewEvidenceRepositoryInterface::class);

    $holder->beginTransaction();
    $early = review("pgsql_concurrent");
    $late = review();

    $whileOpen = $feed->since(LogPosition::start(), 100);
    $holder->commit();
    $onceCommitted = $feed->since($whileOpen->next, 100);

    expect([...reviewIds($whileOpen->records), ...reviewIds($onceCommitted->records)])->toBe([$early, $late]);
});

it("puts every review on the wire as the log holds it", function () {
    $productId = review(comment: "Mantap");
    $driverId = review(kind: "DRIVER");

    $response = ask(new ReviewsSinceRequest);
    $reviews = iterator_to_array($response->getReviews());

    expect($reviews)->toHaveCount(2)
        ->and($reviews[0]->getReviewId())->toBe($productId)
        ->and($reviews[0]->getKind())->toBe(ReviewKind::REVIEW_KIND_PRODUCT)
        ->and($reviews[0]->getSubjectId())->toBe("TSHIRT-BLK-M")
        ->and($reviews[0]->getMerchantPrincipalId())->toBe("3c9a77b1-58de-4a01-8f2e-6d4b19c0a8f3")
        ->and($reviews[0]->getCommentSha256())->toBe(hash("sha256", "Mantap", true))
        ->and($reviews[0]->getCreatedAt()->getSeconds())->toBeGreaterThan(0)
        ->and($reviews[1]->getReviewId())->toBe($driverId)
        ->and($reviews[1]->getKind())->toBe(ReviewKind::REVIEW_KIND_DRIVER)
        ->and($reviews[1]->getMerchantPrincipalId())->toBe("")
        ->and($reviews[1]->getCommentSha256())->toBe("")
        ->and($response->getHasMore())->toBeFalse()
        ->and($response->getNext()->getSequence())->toBe($reviews[1]->getPosition()->getSequence())
        ->and($response->getNext()->getTransactionId())->toBe($reviews[1]->getPosition()->getTransactionId());
});

it("serves a hundred by default and never more than five hundred", function () {
    DB::transaction(function () {
        for ($i = 0; $i < 501; $i++) {
            review();
        }
    });

    $default = ask(new ReviewsSinceRequest);
    $capped = ask((new ReviewsSinceRequest)->setLimit(10_000));

    expect(count($default->getReviews()))->toBe(100)
        ->and($default->getHasMore())->toBeTrue()
        ->and(count($capped->getReviews()))->toBe(500)
        ->and($capped->getHasMore())->toBeTrue();
});

it("refuses a cursor no row can have", function () {
    $cursor = (new LogCursor)->setTransactionId(1)->setSequence(-1);

    try {
        ask((new ReviewsSinceRequest)->setAfter($cursor));
        $this->fail("a cursor beyond every row was answered");
    } catch (GRPCException $ex) {
        expect($ex->getCode())->toBe(StatusCode::INVALID_ARGUMENT);
    }
});

it("answers unavailable, not an empty log, when the log cannot be read", function () {
    review();
    DB::statement("ALTER TABLE review_evidence RENAME TO review_evidence_elsewhere");

    try {
        ask(new ReviewsSinceRequest);
        $this->fail("an unreadable log was answered");
    } catch (GRPCException $ex) {
        expect($ex->getCode())->toBe(StatusCode::UNAVAILABLE)
            ->and($ex->getMessage())->not->toContain("review_evidence_elsewhere");
    }
});

it("writes every review that existed before the log, in the order they were written", function () {
    Artisan::call("migrate:rollback", ["--step" => 2]);

    $functions = DB::selectOne(
        "SELECT count(*) AS n FROM pg_proc WHERE proname LIKE 'review_%' OR proname LIKE '%_is_logged'"
    );

    expect(DB::selectOne("SELECT to_regclass('review_evidence') AS log")->log)->toBeNull()
        ->and($functions->n)->toBe(0);

    $older = (string) Str::uuid();
    $newer = (string) Str::uuid();

    DB::table("driver_ratings")->insert([
        "id" => $newer,
        "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0f",
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "driver_principal_id" => "b7e2c05f-9a34-4c88-b1d6-0e7a3f52d914",
        "rating" => 5,
        "comment" => "cepat",
        "created_at" => "2026-10-02 10:00:00",
        "updated_at" => "2026-10-02 10:00:00",
    ]);
    DB::table("product_reviews")->insert([
        "id" => $older,
        "order_id" => "5f2b8c1e-4a7d-4e93-b6c0-1d8e2f3a4b0e",
        "customer_principal_id" => "11111111-2222-3333-4444-555555555555",
        "product_id" => "MUG-WHT",
        "merchant_principal_id" => null,
        "rating" => 3,
        "comment" => null,
        "created_at" => "2026-10-01 10:00:00",
        "updated_at" => "2026-10-01 10:00:00",
    ]);

    Artisan::call("migrate");

    $records = app(ReviewEvidenceRepositoryInterface::class)->since(LogPosition::start(), 10)->records;

    expect(reviewIds($records))->toBe([$older, $newer])
        ->and($records[0]->kind)->toBe("PRODUCT")
        ->and($records[0]->createdAt)->toBe(strtotime("2026-10-01 10:00:00 UTC"))
        ->and($records[1]->kind)->toBe("DRIVER")
        ->and($records[1]->commentSha256)->toBe(hash("sha256", "cepat", true));
});
