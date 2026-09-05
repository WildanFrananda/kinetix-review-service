<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Throwable;

final class TokenVerifier {
    private readonly CachedKeySet $keys;

    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        string $jwksUri,
        ?ClientInterface $http = null
    ) {
        $this->keys = new CachedKeySet(
            $jwksUri,
            $http ?? new Client(),
            new HttpFactory(),
            new Psr16Adapter(Cache::store()),
            300,
            true
        );
    }

    public function verifyAccess(string $token): AccessClaims {
        try {
            $payload = (array) JWT::decode($token, $this->keys);
        } catch (Throwable) {
            throw new InvalidTokenException("the token could not be verified");
        }

        if (($payload["iss"] ?? null) !== $this->issuer) {
            throw new InvalidTokenException("the token could not be verified");
        }

        if (! $this->audienceMatches($payload["aud"] ?? null)) {
            throw new InvalidTokenException("the token could not be verified");
        }

        if (($payload["token_use"] ?? null) !== "access") {
            throw new InvalidTokenException("the token could not be verified");
        }

        try {
            return AccessClaims::fromPayload($payload);
        } catch (Throwable) {
            throw new InvalidTokenException("the token could not be verified");
        }
    }

    private function audienceMatches(mixed $aud): bool {
        if (is_string($aud)) {
            return $aud === $this->audience;
        }
        if (is_array($aud)) {
            return in_array($this->audience, $aud, true);
        }

        return false;
    }
}
