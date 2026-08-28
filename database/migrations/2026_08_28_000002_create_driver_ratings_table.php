<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create("driver_ratings", function (Blueprint $table) {
            $table->uuid("id")->primary();
            $table->string("order_id")->index();
            $table->bigInteger("customer_id")->index();
            $table->bigInteger("driver_id")->index();
            $table->integer("rating");
            $table->text("comment")->nullable();
            $table->timestamps();

            $table->unique(["order_id", "driver_id"]);
        });
    }

    public function down(): void {
        Schema::dropIfExists("driver_ratings");
    }
};
