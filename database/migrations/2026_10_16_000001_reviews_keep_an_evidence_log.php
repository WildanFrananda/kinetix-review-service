<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (DB::getDriverName() !== "pgsql") {
            throw new RuntimeException(
                "The review evidence log needs Postgres: its cursor is a transaction id (xid8) and "
                    . "triggers write and guard it."
            );
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE review_evidence (
    sequence bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transaction_id xid8 NOT NULL DEFAULT pg_current_xact_id(),
    review_id uuid NOT NULL UNIQUE,
    kind varchar(10) NOT NULL CHECK (kind IN ('PRODUCT', 'DRIVER')),
    order_id varchar(255) NOT NULL,
    subject_id varchar(255) NOT NULL,
    author_principal_id varchar(64) NOT NULL,
    merchant_principal_id varchar(64),
    rating integer NOT NULL,
    comment_sha256 bytea CHECK (comment_sha256 IS NULL OR octet_length(comment_sha256) = 32),
    created_at timestamptz NOT NULL
);

CREATE INDEX review_evidence_position ON review_evidence (transaction_id, sequence);

CREATE OR REPLACE FUNCTION review_comment_sha256(comment text) RETURNS bytea
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN comment IS NULL THEN NULL ELSE sha256(convert_to(comment, 'UTF8')) END
$$;

INSERT INTO review_evidence (
    review_id, kind, order_id, subject_id, author_principal_id, merchant_principal_id, rating,
    comment_sha256, created_at
)
SELECT r.id, r.kind, r.order_id, r.subject_id, r.author_principal_id, r.merchant_principal_id, r.rating,
       review_comment_sha256(r.comment), r.created_at AT TIME ZONE 'UTC'
FROM (
    SELECT id, 'PRODUCT' AS kind, order_id, product_id AS subject_id,
           customer_principal_id AS author_principal_id, merchant_principal_id, rating, comment, created_at
    FROM product_reviews
    UNION ALL
    SELECT id, 'DRIVER', order_id, driver_principal_id, customer_principal_id, NULL, rating, comment,
           created_at
    FROM driver_ratings
) r
ORDER BY r.created_at, r.id;

CREATE OR REPLACE FUNCTION review_evidence_is_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'review_evidence is append-only: % refused', TG_OP;
END
$$;

CREATE TRIGGER review_evidence_no_update_or_delete
    BEFORE UPDATE OR DELETE ON review_evidence
    FOR EACH ROW EXECUTE FUNCTION review_evidence_is_append_only();

CREATE TRIGGER review_evidence_no_truncate
    BEFORE TRUNCATE ON review_evidence
    FOR EACH STATEMENT EXECUTE FUNCTION review_evidence_is_append_only();

CREATE OR REPLACE FUNCTION review_evidence_comes_from_a_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF pg_trigger_depth() < 2 THEN
        RAISE EXCEPTION 'review_evidence is written by the review tables'' triggers only: a direct INSERT refused';
    END IF;
    RETURN NEW;
END
$$;

CREATE TRIGGER review_evidence_only_from_reviews
    BEFORE INSERT ON review_evidence
    FOR EACH ROW EXECUTE FUNCTION review_evidence_comes_from_a_review();

CREATE OR REPLACE FUNCTION product_review_is_logged() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO review_evidence (
        review_id, kind, order_id, subject_id, author_principal_id, merchant_principal_id, rating,
        comment_sha256, created_at
    ) VALUES (
        NEW.id, 'PRODUCT', NEW.order_id, NEW.product_id, NEW.customer_principal_id,
        NEW.merchant_principal_id, NEW.rating, review_comment_sha256(NEW.comment),
        NEW.created_at AT TIME ZONE 'UTC'
    );
    RETURN NULL;
END
$$;

CREATE TRIGGER product_reviews_are_logged
    AFTER INSERT ON product_reviews
    FOR EACH ROW EXECUTE FUNCTION product_review_is_logged();

CREATE OR REPLACE FUNCTION driver_rating_is_logged() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO review_evidence (
        review_id, kind, order_id, subject_id, author_principal_id, merchant_principal_id, rating,
        comment_sha256, created_at
    ) VALUES (
        NEW.id, 'DRIVER', NEW.order_id, NEW.driver_principal_id, NEW.customer_principal_id, NULL,
        NEW.rating, review_comment_sha256(NEW.comment), NEW.created_at AT TIME ZONE 'UTC'
    );
    RETURN NULL;
END
$$;

CREATE TRIGGER driver_ratings_are_logged
    AFTER INSERT ON driver_ratings
    FOR EACH ROW EXECUTE FUNCTION driver_rating_is_logged();
SQL);
    }

    public function down(): void {
        DB::unprepared(<<<'SQL'
DROP TRIGGER driver_ratings_are_logged ON driver_ratings;
DROP FUNCTION driver_rating_is_logged();
DROP TRIGGER product_reviews_are_logged ON product_reviews;
DROP FUNCTION product_review_is_logged();
DROP TRIGGER review_evidence_only_from_reviews ON review_evidence;
DROP FUNCTION review_evidence_comes_from_a_review();
DROP TRIGGER review_evidence_no_truncate ON review_evidence;
DROP TRIGGER review_evidence_no_update_or_delete ON review_evidence;
DROP FUNCTION review_evidence_is_append_only();
DROP TABLE review_evidence;
DROP FUNCTION review_comment_sha256(text);
SQL);
    }
};
