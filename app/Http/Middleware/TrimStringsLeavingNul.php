<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings;

final class TrimStringsLeavingNul extends TrimStrings {
    protected function transform(mixed $key, mixed $value): mixed {
        if (is_string($value) && str_contains($value, "\0")) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
