<?php

declare(strict_types=1);

namespace App\Providers;

use App\Clients\GrpcOrderClient;
use App\Console\OctaneDrain;
use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Observability\MetricsRegistry;
use App\Repositories\EloquentDriverRatingRepository;
use App\Repositories\EloquentProductReviewRepository;
use App\Security\TokenVerifier;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(ProductReviewRepositoryInterface::class, EloquentProductReviewRepository::class);
        $this->app->bind(DriverRatingRepositoryInterface::class, EloquentDriverRatingRepository::class);
        $this->app->singleton(OrderClientInterface::class, GrpcOrderClient::class);
        $this->app->singleton(MetricsRegistry::class, static function (): MetricsRegistry {
            return new MetricsRegistry((string) config("app.version"));
        });

        $this->app->singleton(TokenVerifier::class, static function (): TokenVerifier {
            return new TokenVerifier(
                self::required("JWT_ISSUER"),
                self::required("JWT_AUDIENCE"),
                self::required("IDENTITY_JWKS_URL"),
                new Client(["connect_timeout" => 2.0, "timeout" => 3.0])
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

    public function boot(): void {
        if ($this->app->runningInConsole() && OctaneDrain::shouldInstall($_SERVER["argv"] ?? [])) {
            OctaneDrain::install();
        }
    }
}
