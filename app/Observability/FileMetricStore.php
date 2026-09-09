<?php

declare(strict_types=1);

namespace App\Observability;

use App\Contracts\Observability\MetricStoreInterface;
use JsonException;

final class FileMetricStore implements MetricStoreInterface {
    /** @var array<string, float> */
    private array $pending = [];

    public function __construct(private readonly string $path) {}

    public function add(string $key, float $delta): void {
        $this->pending[$key] = ($this->pending[$key] ?? 0.0) + $delta;
    }

    public function commit(): void {
        if ($this->pending === []) {
            return;
        }

        $deltas = $this->pending;
        $this->pending = [];

        $handle = $this->open();

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new MetricStoreUnavailableException("could not take an exclusive lock on {$this->path}");
            }

            $state = self::decode(self::slurp($handle), $this->path);

            foreach ($deltas as $key => $delta) {
                $state[$key] = ($state[$key] ?? 0.0) + $delta;
            }

            self::overwrite($handle, self::encode($state, $this->path), $this->path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, float>
     */
    public function read(): array {
        $this->commit();

        $handle = $this->open();

        try {
            if (! flock($handle, LOCK_SH)) {
                throw new MetricStoreUnavailableException("could not take a shared lock on {$this->path}");
            }

            return self::decode(self::slurp($handle), $this->path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open() {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new MetricStoreUnavailableException("{$directory} does not exist and could not be created");
        }

        $handle = @fopen($this->path, "c+");

        if ($handle === false) {
            throw new MetricStoreUnavailableException("could not open {$this->path} for reading and writing");
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private static function slurp($handle): string {
        rewind($handle);

        $raw = stream_get_contents($handle);

        if ($raw === false) {
            throw new MetricStoreUnavailableException("could not read the metric store");
        }

        return $raw;
    }

    /**
     * @param resource $handle
     */
    private static function overwrite($handle, string $contents, string $path): void {
        if (! ftruncate($handle, 0) || rewind($handle) === false) {
            throw new MetricStoreUnavailableException("could not truncate {$path} before rewriting it");
        }

        $written = fwrite($handle, $contents);

        if ($written === false || $written !== strlen($contents) || ! fflush($handle)) {
            throw new MetricStoreUnavailableException("only part of the metric store was written to {$path}");
        }
    }

    /**
     * @return array<string, float>
     */
    private static function decode(string $raw, string $path): array {
        if (trim($raw) === "") {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MetricStoreUnavailableException(
                "{$path} is not readable as JSON: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        if (! is_array($decoded)) {
            throw new MetricStoreUnavailableException("{$path} does not hold a map of samples");
        }

        $state = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! is_int($value) && ! is_float($value)) {
                throw new MetricStoreUnavailableException("{$path} holds a sample that is not a named number");
            }

            $state[$key] = (float) $value;
        }

        return $state;
    }

    /**
     * @param array<string, float> $state
     */
    private static function encode(array $state, string $path): string {
        try {
            return json_encode($state, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MetricStoreUnavailableException(
                "the metric store could not be encoded for {$path}: {$exception->getMessage()}",
                0,
                $exception
            );
        }
    }
}
