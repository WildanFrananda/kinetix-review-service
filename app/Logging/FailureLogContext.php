<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class FailureLogContext {
    /**
     * @return array<string, string>
     */
    public static function of(Throwable $failure): array {
        $databaseError = self::databaseError($failure);
        if ($databaseError === null) {
            return [
                "exception" => $failure::class,
                "reason" => $failure->getMessage(),
            ];
        }

        $context = ["exception" => $failure::class];
        $sqlState = $databaseError->errorInfo[0] ?? $databaseError->getCode();
        if (is_string($sqlState) && $sqlState !== "") {
            $context["sqlstate"] = $sqlState;
        }

        if ($databaseError instanceof QueryException) {
            $context["sql"] = $databaseError->getSql();
        }

        return $context;
    }

    private static function databaseError(Throwable $failure): QueryException|PDOException|null {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return $cause;
            }
        }

        return null;
    }
}
