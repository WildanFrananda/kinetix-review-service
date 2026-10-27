<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Validation\WithoutNulCharacter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class Controller {
    private const MAX_PAGE = 10_000;

    protected function listingPage(Request $request, string $subjectField, string $subject): int {
        $validated = Validator::make(
            [$subjectField => $subject, "page" => $request->input("page")],
            [
                $subjectField => [new WithoutNulCharacter],
                "page" => ["nullable", "integer", "min:1", "max:" . self::MAX_PAGE],
            ]
        )->validate();

        return (int) ($validated["page"] ?? 1);
    }
}
