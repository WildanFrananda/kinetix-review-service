<?php

declare(strict_types=1);

namespace App\Providers;

use App\Clients\GrpcOrderClient;
use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Repositories\EloquentDriverRatingRepository;
use App\Repositories\EloquentProductReviewRepository;
use App\Security\TokenVerifier;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(ProductReviewRepositoryInterface::class, EloquentProductReviewRepository::class);
        $this->app->bind(DriverRatingRepositoryInterface::class, EloquentDriverRatingRepository::class);
        $this->app->singleton(OrderClientInterface::class, GrpcOrderClient::class);

        $this->app->singleton(TokenVerifier::class, static function (): TokenVerifier {
            return new TokenVerifier(
                self::required("JWT_ISSUER"),
                self::required("JWT_AUDIENCE"),
                self::required("IDENTITY_JWKS_URL")
            );
        });
    }

    private static function required(string $name): string {
        $value = env($name);
        if (! is_string($value) || $value === "") {
            throw new RuntimeException("{$name} is required and has no default.");
        }

        return $value;
    }

    public function boot(): void {}
}
