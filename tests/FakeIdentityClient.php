<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Clients\IdentityClientInterface;

class FakeIdentityClient implements IdentityClientInterface {
    private array $users = [
        1001 => ["user_id" => 1001, "role" => "customer"],
        1002 => ["user_id" => 1002, "role" => "customer"],
    ];

    public function addUser(int $userId, array $data): void {
        $this->users[$userId] = $data;
    }

    public function getUserProfile(int $userId): ?array {
        return $this->users[$userId] ?? null;
    }

    public function validateToken(string $token): ?array {
        return ["valid" => true, "user_id" => 1001];
    }
}
