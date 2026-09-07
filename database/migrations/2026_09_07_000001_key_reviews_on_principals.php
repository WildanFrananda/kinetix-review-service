<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        $this->refuseIfPopulated();

        Schema::table("product_reviews", function (Blueprint $table) {
            $table->dropIndex(["customer_id"]);
            $table->dropIndex(["merchant_id"]);
            $table->dropColumn(["customer_id", "merchant_id"]);
        });

        Schema::table("product_reviews", function (Blueprint $table) {
            $table->string("customer_principal_id", 64)->after("order_id")->index();
            $table->string("merchant_principal_id", 64)->nullable()->after("product_id")->index();
        });

        Schema::table("driver_ratings", function (Blueprint $table) {
            $table->dropUnique(["order_id", "driver_id"]);
            $table->dropIndex(["customer_id"]);
            $table->dropIndex(["driver_id"]);
            $table->dropColumn(["customer_id", "driver_id"]);
        });

        Schema::table("driver_ratings", function (Blueprint $table) {
            $table->string("customer_principal_id", 64)->after("order_id")->index();
            $table->string("driver_principal_id", 64)->after("customer_principal_id")->index();
            $table->unique(["order_id", "driver_principal_id"]);
        });
    }

    public function down(): void {
        $this->refuseIfPopulated();

        Schema::table("driver_ratings", function (Blueprint $table) {
            $table->dropUnique(["order_id", "driver_principal_id"]);
            $table->dropColumn(["customer_principal_id", "driver_principal_id"]);
        });

        Schema::table("driver_ratings", function (Blueprint $table) {
            $table->bigInteger("customer_id")->index();
            $table->bigInteger("driver_id")->index();
            $table->unique(["order_id", "driver_id"]);
        });

        Schema::table("product_reviews", function (Blueprint $table) {
            $table->dropColumn(["customer_principal_id", "merchant_principal_id"]);
        });

        Schema::table("product_reviews", function (Blueprint $table) {
            $table->bigInteger("customer_id")->index();
            $table->bigInteger("merchant_id")->nullable()->index();
        });
    }

    private function refuseIfPopulated(): void {
        foreach (["product_reviews", "driver_ratings"] as $table) {
            $rows = DB::table($table)->count();
            if ($rows > 0) {
                throw new RuntimeException(
                    "{$table} holds {$rows} row(s) and this migration has no way to convert them. "
                        . "It drops the account columns outright, which is only safe on an empty "
                        . "table. Resolve each id through identity's ResolvePrincipal first — see "
                        . "PrincipalBackfill in kinetix-order-service for the shape."
                );
            }
        }
    }
};
