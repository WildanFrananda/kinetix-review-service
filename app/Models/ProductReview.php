<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model {
    use HasFactory, HasUuids;

    protected $table = "product_reviews";

    protected $fillable = [
        "order_id",
        "customer_id",
        "product_id",
        "merchant_id",
        "rating",
        "comment",
    ];

    protected $casts = [
        "customer_id" => "integer",
        "merchant_id" => "integer",
        "rating" => "integer",
    ];
}
