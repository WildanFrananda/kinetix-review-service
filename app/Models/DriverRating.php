<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverRating extends Model {
    use HasFactory, HasUuids;

    protected $table = "driver_ratings";

    protected $fillable = [
        "order_id",
        "customer_id",
        "driver_id",
        "rating",
        "comment",
    ];

    protected $casts = [
        "customer_id" => "integer",
        "driver_id" => "integer",
        "rating" => "integer",
    ];
}
