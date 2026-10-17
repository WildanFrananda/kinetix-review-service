<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\ReviewEvidenceRepositoryInterface;
use App\Evidence\LogPosition;
use App\Evidence\ReviewEvidencePage;
use App\Evidence\ReviewEvidenceRecord;
use App\Models\ReviewEvidence;

class EloquentReviewEvidenceRepository implements ReviewEvidenceRepositoryInterface {
    public function since(LogPosition $after, int $limit): ReviewEvidencePage {
        $rows = ReviewEvidence::query()
            ->select([
                "sequence",
                "kind",
                "order_id",
                "subject_id",
                "author_principal_id",
                "merchant_principal_id",
                "rating",
            ])
            ->selectRaw("transaction_id::text AS transaction_id")
            ->selectRaw("review_id::text AS review_id")
            ->selectRaw("encode(comment_sha256, 'hex') AS comment_sha256")
            ->selectRaw("extract(epoch FROM created_at)::bigint AS created_at")
            ->whereRaw(
                "(transaction_id, sequence) > (CAST(CAST(? AS text) AS xid8), ?)",
                [(string) $after->transactionId, $after->sequence]
            )
            ->whereRaw("transaction_id < pg_snapshot_xmin(pg_current_snapshot())")
            ->orderBy("transaction_id")
            ->orderBy("sequence")
            ->limit($limit + 1)
            ->get();

        $records = $rows->take($limit)->map(self::record(...))->values()->all();
        $next = $records === [] ? $after : $records[count($records) - 1]->position;

        return new ReviewEvidencePage($records, $next, $rows->count() > $limit);
    }

    private static function record(ReviewEvidence $row): ReviewEvidenceRecord {
        $commentSha256 = $row->getAttribute("comment_sha256");
        $merchant = $row->getAttribute("merchant_principal_id");

        return new ReviewEvidenceRecord(
            new LogPosition((int) $row->getAttribute("transaction_id"), (int) $row->getAttribute("sequence")),
            (string) $row->getAttribute("review_id"),
            (string) $row->getAttribute("kind"),
            (string) $row->getAttribute("order_id"),
            (string) $row->getAttribute("subject_id"),
            (string) $row->getAttribute("author_principal_id"),
            $merchant === null ? null : (string) $merchant,
            (int) $row->getAttribute("rating"),
            $commentSha256 === null ? null : (string) hex2bin((string) $commentSha256),
            (int) $row->getAttribute("created_at"),
        );
    }
}
