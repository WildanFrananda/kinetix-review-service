<?php

declare(strict_types=1);

namespace App\Contracts\Clients;

interface IdentityClientInterface {
    public function getUserProfile(int $userId): ?array;

    public function validateToken(string $token): ?array;
}
