<?php

declare(strict_types=1);

namespace App\Providers;

use App\Clients\GrpcOrderClient;
use App\Clients\IdentityGrpcClient;
use App\Contracts\Clients\IdentityClientInterface;
use App\Contracts\Clients\OrderClientInterface;
use App\Contracts\Repositories\DriverRatingRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Repositories\EloquentDriverRatingRepository;
use App\Repositories\EloquentProductReviewRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(ProductReviewRepositoryInterface::class, EloquentProductReviewRepository::class);
        $this->app->bind(DriverRatingRepositoryInterface::class, EloquentDriverRatingRepository::class);
        $this->app->singleton(OrderClientInterface::class, GrpcOrderClient::class);
        $this->app->singleton(IdentityClientInterface::class, IdentityGrpcClient::class);
    }

    public function boot(): void {}
}
