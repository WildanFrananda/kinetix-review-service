<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const HYPHENATED = "[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}";

    private const HEX_DIGITS_ONLY = "lower(regexp_replace(order_id, '[^0-9A-Fa-f]', '', 'g'))";

    private const UNIQUE_KEYS = [
        "product_reviews" => "product_id",
        "driver_ratings" => "driver_principal_id",
    ];

    public function up(): void {
        if (DB::getDriverName() !== "pgsql") {
            throw new RuntimeException("Review's order ids are stored as Postgres uuid values.");
        }

        $this->refuseUnreadableOrderIds();
        $this->refuseCollisions();

        foreach (array_keys(self::UNIQUE_KEYS) as $table) {
            DB::statement(
                "ALTER TABLE {$table} ALTER COLUMN order_id TYPE uuid USING (" . self::HEX_DIGITS_ONLY . ")::uuid"
            );
        }
    }

    public function down(): void {
        foreach (array_keys(self::UNIQUE_KEYS) as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN order_id TYPE varchar(255) USING order_id::text");
        }
    }

    private function refuseUnreadableOrderIds(): void {
        $accepted = "^(\\{" . self::HYPHENATED . "\\}|\\(" . self::HYPHENATED . "\\)|"
            . self::HYPHENATED . "|[0-9A-Fa-f]{32})$";

        $unreadable = [];
        foreach (array_keys(self::UNIQUE_KEYS) as $table) {
            $unreadable[$table] = (int) DB::selectOne(
                "SELECT count(*) AS n FROM {$table} WHERE order_id !~ ?",
                [$accepted]
            )->n;
        }

        if (array_sum($unreadable) > 0) {
            throw new RuntimeException(
                "{$unreadable["product_reviews"]} product review(s) and {$unreadable["driver_ratings"]} driver "
                    . "rating(s) carry an order_id that is not a UUID in a spelling order reads. Nothing was "
                    . "changed. See CLAUDE.md, \"An order is named by its canonical UUID\"."
            );
        }
    }

    private function refuseCollisions(): void {
        $collisions = [];
        foreach (self::UNIQUE_KEYS as $table => $subject) {
            $collisions[$table] = DB::selectOne(
                "SELECT count(*) AS groups, coalesce(sum(n), 0) AS rows FROM ("
                    . "SELECT count(*) AS n FROM {$table} GROUP BY " . self::HEX_DIGITS_ONLY . ", {$subject} "
                    . "HAVING count(*) > 1) AS colliding"
            );
        }

        $product = $collisions["product_reviews"];
        $driver = $collisions["driver_ratings"];
        if ((int) $product->groups + (int) $driver->groups > 0) {
            throw new RuntimeException(
                "Rewriting order_id to its canonical form would make {$product->groups} product review group(s) "
                    . "holding {$product->rows} row(s) and {$driver->groups} driver rating group(s) holding "
                    . "{$driver->rows} row(s) collide on their unique keys. Nothing was changed. See CLAUDE.md, "
                    . "\"An order is named by its canonical UUID\"."
            );
        }
    }
};
