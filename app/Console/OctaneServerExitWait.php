<?php

declare(strict_types=1);

namespace App\Console;

use Illuminate\Support\Facades\Log;
use Throwable;

final class OctaneServerExitWait {
    private const GRACE_SECONDS = 25.0;

    private const POLL_MICROSECONDS = 50_000;

    public const COMMAND = "octane:start";

    /**
     * @param array<int|string, mixed> $argv
     */
    public static function shouldInstall(array $argv): bool {
        return in_array(self::COMMAND, $argv, true);
    }

    public static function install(): void {
        if (! function_exists("pcntl_waitpid")) {
            Log::warning("pcntl is not loaded, so nothing will hold this process open while the "
                . "server exits. On SIGTERM the container may go before the server has stopped."
            );

            return;
        }

        register_shutdown_function(static function (): void {
            self::wait();
        });
    }

    private static function wait(): void {
        try {
            $reaped = self::reap();

            if ($reaped !== 0) {
                Log::info("the artisan process is exiting; the server process was already gone", [
                    "waitpid" => $reaped,
                ]);

                return;
            }

            Log::info("the artisan process is exiting; waiting for the server process", [
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
                ]
            );
        } catch (Throwable $exception) {
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
