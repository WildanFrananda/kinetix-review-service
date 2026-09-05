<?php

declare(strict_types=1);

namespace Tests;

use App\Security\TokenVerifier;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OpenSSLAsymmetricKey;
use Psr\Http\Client\ClientInterface;

final class IdentityTokens {
    private static ?OpenSSLAsymmetricKey $key = null;
    private static ?string $kid = null;

    private static function key(): OpenSSLAsymmetricKey {
        if (self::$key === null) {
            self::$key = openssl_pkey_new([
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ]);
            self::$kid = "test-" . substr(hash("sha256", (string) openssl_pkey_get_details(self::$key)["rsa"]["n"]), 0, 16);
        }

        return self::$key;
    }

    public static function kid(): string {
        self::key();

        return (string) self::$kid;
    }

    public static function jwksDocument(): array {
        $details = openssl_pkey_get_details(self::key());

        return [
            "keys" => [[
                "kty" => "RSA",
                "alg" => "RS256",
                "use" => "sig",
                "kid" => self::kid(),
                "n" => rtrim(strtr(base64_encode($details["rsa"]["n"]), "+/", "-_"), "="),
                "e" => rtrim(strtr(base64_encode($details["rsa"]["e"]), "+/", "-_"), "="),
            ]],
        ];
    }

    public static function jwksClient(): ClientInterface {
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 50, new Response(
            200,
            ["Content-Type" => "application/json"],
            (string) json_encode(self::jwksDocument())
        ))));

        return new Client(["handler" => $stack]);
    }

    public static function verifier(): TokenVerifier {
        return new TokenVerifier(
            (string) env("JWT_ISSUER"),
            (string) env("JWT_AUDIENCE"),
            "http://identity.test/.well-known/jwks.json",
            self::jwksClient()
        );
    }

    public static function token(array $overrides = []): string {
        $now = time();
        $claims = array_merge([
            "sub" => "11111111-2222-3333-4444-555555555555",
            "uid" => 1001,
            "email" => "customer@kinetix.test",
            "role" => "customer",
            "token_use" => "access",
            "iss" => env("JWT_ISSUER"),
            "aud" => env("JWT_AUDIENCE"),
            "iat" => $now,
            "exp" => $now + 900,
        ], $overrides);

        return JWT::encode($claims, self::key(), "RS256", self::kid());
    }

    public static function bearer(array $overrides = []): array {
        return ["Authorization" => "Bearer " . self::token($overrides)];
    }
}
