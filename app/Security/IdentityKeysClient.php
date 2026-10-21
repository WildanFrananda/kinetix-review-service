<?php

declare(strict_types=1);

namespace App\Security;

use App\Observability\RequestId;
use Illuminate\Support\Facades\Log;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class IdentityKeysClient implements ClientInterface {
    private const LAST_FETCH_FAILED = "identity-jwks-last-fetch-failed";

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CacheItemPoolInterface $cache
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface {
        try {
            $response = $this->http->sendRequest($request);
        } catch (Throwable $ex) {
            throw $this->failed($request, $ex::class . ": " . $ex->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            throw $this->failed($request, "answered HTTP {$response->getStatusCode()}");
        }

        if (! self::holdsKeys((string) $response->getBody())) {
            throw $this->failed($request, "answered a document that holds no keys");
        }

        $this->cache->deleteItem(self::LAST_FETCH_FAILED);

        return $response;
    }

    public function lastFetchFailed(): bool {
        return $this->cache->getItem(self::LAST_FETCH_FAILED)->isHit();
    }

    private function failed(RequestInterface $request, string $reason): IdentityKeysUnavailableException {
        $this->cache->save($this->cache->getItem(self::LAST_FETCH_FAILED)->set(time()));

        Log::warning(
            "identity's signing keys could not be fetched, so no token can be checked",
            [
                "jwks_uri" => (string) $request->getUri(),
                "reason" => $reason,
                "request_id" => RequestId::current() ?? "-",
            ]
        );

        return IdentityKeysUnavailableException::unreachable();
    }

    private static function holdsKeys(string $body): bool {
        $document = json_decode($body, true);

        return is_array($document) && is_array($document["keys"] ?? null) && $document["keys"] !== [];
    }
}
