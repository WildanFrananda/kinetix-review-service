<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class WithoutNulCharacter implements ValidationRule {
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail("The :attribute field must not contain a NUL character (U+0000).");
        }
    }
}
