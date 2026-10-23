<?php

declare(strict_types=1);

namespace App\Resilience;

use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

final class DatabaseOutage {
    use DetectsLostConnections;

    private const array SERVER_GOING_AWAY = ["57P01", "57P02", "57P03"];

    private const array DRIVER_OWN_ERRORS = ["", "HY000"];

    public function causedBy(Throwable $failure): bool {
        if ($failure instanceof LostConnectionException) {
            return true;
        }

        $driverError = self::driverError($failure);
        if ($driverError === null) {
            return false;
        }

        $sqlState = self::sqlState($driverError);
        if (str_starts_with($sqlState, "08") || in_array($sqlState, self::SERVER_GOING_AWAY, true)) {
            return true;
        }

        return in_array($sqlState, self::DRIVER_OWN_ERRORS, true) && $this->causedByLostConnection($driverError);
    }

    public static function reason(Throwable $failure): string {
        $cause = self::driverError($failure) ?? $failure;

        return $cause::class . ": " . $cause->getMessage();
    }

    private static function driverError(Throwable $failure): ?PDOException {
        if ($failure instanceof QueryException) {
            $previous = $failure->getPrevious();

            return $previous instanceof PDOException ? $previous : null;
        }

        return $failure instanceof PDOException ? $failure : null;
    }

    private static function sqlState(PDOException $driverError): string {
        $sqlState = $driverError->errorInfo[0] ?? $driverError->getCode();

        return is_string($sqlState) ? $sqlState : "";
    }
}
