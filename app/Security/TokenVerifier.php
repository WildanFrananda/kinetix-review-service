<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\CachedKeySet;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Cache;
use OutOfBoundsException;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Throwable;

final class TokenVerifier {
    private readonly IdentityKeysClient $identity;

    private readonly CachedKeySet $keys;

    public function __construct(
        private readonly string $issuer,
        private readonly string $audience,
        string $jwksUri,
        ?ClientInterface $http = null
    ) {
        $cache = new Psr16Adapter(Cache::store());
        $this->identity = new IdentityKeysClient($http ?? new Client, $cache);
        $this->keys = new CachedKeySet(
            $jwksUri,
            $this->identity,
            new HttpFactory,
            $cache,
            300,
            true
        );
    }

    public function verifyAccess(string $token): AccessClaims {
        try {
            $payload = (array) JWT::decode($token, $this->keys);
        } catch (IdentityKeysUnavailableException $ex) {
            throw $ex;
        } catch (OutOfBoundsException) {
            if ($this->identity->lastFetchFailed()) {
                throw IdentityKeysUnavailableException::unreachable();
            }

            throw new InvalidTokenException("the token could not be verified");
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
