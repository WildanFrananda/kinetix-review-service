<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Log;

final class OctaneDrain {
    private const GRACE_SECONDS = 25.0;

    private const POLL_MICROSECONDS = 50_000;

    public const COMMAND = "octane:start";

    public static function shouldInstall(array $argv): bool {
        return in_array(self::COMMAND, $argv, true);
    }

    public static function install(): void {
        if (! function_exists("pcntl_waitpid")) {
            return;
        }

        register_shutdown_function(static function (): void {
            self::drain();
        });
    }

    private static function drain(): void {
        try {
            if (self::reap() !== 0) {
                return;
            }

            Log::info("stopping: waiting for the server process to exit", [
                "grace_seconds" => self::GRACE_SECONDS,
            ]);

            $waitedFor = self::waitForServer();

            if ($waitedFor !== null) {
                Log::info("the server process exited", ["waited_seconds" => $waitedFor]);

                return;
            }

            Log::error("the server process was still running when the wait window closed; it is "
                . "about to be killed, and anything it was still serving with it", [
                    "grace_seconds" => self::GRACE_SECONDS,
                ]);
        } catch (\Throwable $exception) {
            Log::error("the shutdown wait itself failed; the server is being stopped without it", [
                "exception" => $exception::class,
                "message" => $exception->getMessage(),
            ]);
        }
    }

    private static function reap(): int {
        $status = 0;

        return pcntl_waitpid(-1, $status, WNOHANG);
    }

    private static function waitForServer(): ?float {
        $startedAt = microtime(true);
        $deadline = $startedAt + self::GRACE_SECONDS;

        while (microtime(true) < $deadline) {
            if (self::reap() !== 0) {
                return round(microtime(true) - $startedAt, 3);
            }

            usleep(self::POLL_MICROSECONDS);
        }

        return null;
    }
}
