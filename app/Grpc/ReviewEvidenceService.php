<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Contracts\Repositories\ReviewEvidenceRepositoryInterface;
use App\Evidence\LogPosition;
use App\Evidence\ReviewEvidenceRecord;
use Common\V1\LogCursor;
use Google\Protobuf\Timestamp;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Review\V1\ReviewEvidence;
use Review\V1\ReviewEvidenceServiceInterface;
use Review\V1\ReviewKind;
use Review\V1\ReviewsSinceRequest;
use Review\V1\ReviewsSinceResponse;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

final class ReviewEvidenceService implements ReviewEvidenceServiceInterface {
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;

    public function __construct(private readonly ReviewEvidenceRepositoryInterface $evidence) {}

    public function ReviewsSince(ContextInterface $ctx, ReviewsSinceRequest $in): ReviewsSinceResponse {
        $after = self::position($in->getAfter());

        try {
            $page = $this->evidence->since($after, self::limit($in->getLimit()));
        } catch (QueryException $ex) {
            Log::error("the review evidence log could not be read", ["exception" => $ex->getMessage()]);

            throw GRPCException::create("the review evidence log could not be read", StatusCode::UNAVAILABLE);
        }

        return (new ReviewsSinceResponse)
            ->setReviews(array_map(self::wire(...), $page->records))
            ->setNext(self::cursor($page->next))
            ->setHasMore($page->hasMore);
    }

    private static function position(?LogCursor $cursor): LogPosition {
        if ($cursor === null) {
            return LogPosition::start();
        }

        $transactionId = $cursor->getTransactionId();
        $sequence = $cursor->getSequence();

        if (! is_int($transactionId) || ! is_int($sequence) || $transactionId < 0 || $sequence < 0) {
            throw GRPCException::create("the cursor is beyond any row", StatusCode::INVALID_ARGUMENT);
        }

        return new LogPosition($transactionId, $sequence);
    }

    private static function limit(int $requested): int {
        if ($requested <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($requested, self::MAX_LIMIT);
    }

    private static function cursor(LogPosition $position): LogCursor {
        return (new LogCursor)
            ->setTransactionId($position->transactionId)
            ->setSequence($position->sequence);
    }

    private static function kind(string $kind): int {
        return match ($kind) {
            "PRODUCT" => ReviewKind::REVIEW_KIND_PRODUCT,
            "DRIVER" => ReviewKind::REVIEW_KIND_DRIVER,
        };
    }

    private static function wire(ReviewEvidenceRecord $record): ReviewEvidence {
        return (new ReviewEvidence)
            ->setPosition(self::cursor($record->position))
            ->setReviewId($record->reviewId)
            ->setKind(self::kind($record->kind))
            ->setOrderId($record->orderId)
            ->setSubjectId($record->subjectId)
            ->setAuthorPrincipalId($record->authorPrincipalId)
            ->setMerchantPrincipalId($record->merchantPrincipalId ?? "")
            ->setRating($record->rating)
            ->setCommentSha256($record->commentSha256 ?? "")
            ->setCreatedAt((new Timestamp)->setSeconds($record->createdAt));
    }
}
