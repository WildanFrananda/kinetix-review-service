<?php

declare(strict_types=1);

namespace App\Concurrency;

use RuntimeException;

final class ProcessWideLock {
    public function __construct(private readonly string $path) {}

    /**
     * @template T
     *
     * @param  callable(): T  $critical
     * @return T
     */
    public function hold(callable $critical): mixed {
        $handle = @fopen($this->path, "c");

        if ($handle === false) {
            throw new RuntimeException("{$this->path} cannot be opened, so nothing serialises what it guards");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("{$this->path} cannot be locked, so nothing serialises what it guards");
            }

            try {
                return $critical();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
