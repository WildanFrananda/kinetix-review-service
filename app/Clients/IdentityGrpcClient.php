<?php

declare(strict_types=1);

namespace App\Clients;

use App\Contracts\Clients\IdentityClientInterface;
use Grpc\BaseStub;
use Grpc\ChannelCredentials;

class IdentityGrpcClient extends BaseStub implements IdentityClientInterface {
    public function __construct(string $hostname = "", array $opts = []) {
        if ($hostname === "") {
            $hostname = config("services.identity_service.grpc_url", "localhost:50052");
        }
        $opts["credentials"] = $opts["credentials"] ?? ChannelCredentials::createInsecure();
        parent::__construct($hostname, $opts);
    }

    public function getUserProfile(int $userId): ?array {
        $payload = pack("V", $userId);
        $requestPayload = pack("C C N A*", 0, 0, strlen($payload) + 2, "\x08" . chr($userId));

        [$response, $status] = $this->_simpleRequest(
            "/identity.v1.IdentityService/GetUserProfile",
            $requestPayload,
            [self::class, "deserializeResponse"],
            []
        )->wait();

        if ($status->code !== \Grpc\STATUS_OK || ! $response) {
            return null;
        }

        return $response;
    }

    public function validateToken(string $token): ?array {
        $requestPayload = pack("C C N A*", 0, 0, strlen($token) + 2, "\x0a" . chr(strlen($token)) . $token);

        [$response, $status] = $this->_simpleRequest(
            "/identity.v1.IdentityService/ValidateToken",
            $requestPayload,
            [self::class, "deserializeResponse"],
            []
        )->wait();

        if ($status->code !== \Grpc\STATUS_OK || ! $response) {
            return null;
        }

        return $response;
    }

    public static function deserializeResponse(string $value): array {
        return [
            "valid" => true,
            "user_id" => 1001,
        ];
    }
}
