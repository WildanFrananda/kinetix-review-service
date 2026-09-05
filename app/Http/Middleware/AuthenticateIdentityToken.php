<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\AccessClaims;
use App\Security\InvalidTokenException;
use App\Security\TokenVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateIdentityToken {
    public const ATTRIBUTE = "caller";

    public function __construct(private readonly TokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response {
        $header = (string) $request->header("Authorization", "");
        $parts = explode(" ", $header);

        if (count($parts) !== 2 || strtolower($parts[0]) !== "bearer" || $parts[1] === "") {
            return $this->unauthorized("a bearer token is required");
        }

        try {
            $claims = $this->verifier->verifyAccess($parts[1]);
        } catch (InvalidTokenException) {
            return $this->unauthorized("invalid token");
        }

        $request->attributes->set(self::ATTRIBUTE, $claims);

        return $next($request);
    }

    public static function caller(Request $request): AccessClaims {
        $claims = $request->attributes->get(self::ATTRIBUTE);
        if (! $claims instanceof AccessClaims) {
            throw new \RuntimeException("the caller was read before authentication ran");
        }

        return $claims;
    }

    private function unauthorized(string $message): JsonResponse {
        return response()->json(["error" => "UNAUTHORIZED", "message" => $message], 401);
    }
}
