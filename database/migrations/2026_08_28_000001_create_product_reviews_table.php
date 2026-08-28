<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("product_reviews", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("order_id")->index();
            $table->bigInteger("customer_id")->index();
            $table->string("product_id")->index();
            $table->bigInteger("merchant_id")->nullable()->index();
            $table->integer("rating");
            $table->text("comment")->nullable();
            $table->timestamps();

            $table->unique(["order_id", "product_id"]);
        });
    }

    public function down(): void {
        Schema::dropIfExists("product_reviews");
    }
};
