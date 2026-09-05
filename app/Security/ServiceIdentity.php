<?php

declare(strict_types=1);

namespace App\Security;

use Grpc\ChannelCredentials;
use RuntimeException;

final class ServiceIdentity {
    private const DEFAULT_DIR = "/pki";

    public static function channelCredentials(?string $directory = null): ChannelCredentials {
        $dir = $directory ?? (getenv("KINETIX_PKI_DIR") ?: self::DEFAULT_DIR);

        return ChannelCredentials::createSsl(
            self::read($dir, "ca.pem"),
            self::read($dir, "tls.key"),
            self::read($dir, "tls.crt")
        );
    }

    private static function read(string $dir, string $name): string {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(
                "{$path} is missing. This service's PKI is mounted at {$dir}; issue it with " .
                "kinetix-infrastructure/bin/kinetix-pki issue."
            );
        }

        return $contents;
    }
}
