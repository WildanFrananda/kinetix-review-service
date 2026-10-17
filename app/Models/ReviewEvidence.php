<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewEvidence extends Model {
    public $incrementing = false;

    public $timestamps = false;

    protected $table = "review_evidence";

    protected $primaryKey = "sequence";

    protected $guarded = ["*"];
}
