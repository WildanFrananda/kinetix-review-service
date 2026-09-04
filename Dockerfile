# Review's image: FrankenPHP in worker mode.
#
# Two rewrites happened here. The first replaced `php artisan serve` — PHP's single-request
# development server — with nginx + PHP-FPM, and added the PECL grpc extension, without which
# AppServiceProvider could not resolve OrderClientInterface or IdentityClientInterface at all
# (`Class "Grpc\ChannelCredentials" not found`: the composer package ships PHP stubs, the
# transport lives in the extension).
#
# This is the second: FrankenPHP with Octane worker mode, chosen for the performance the worker
# model actually buys — Laravel is bootstrapped once and reused, and the gRPC channels to
# identity and order survive between requests instead of paying a TCP + HTTP/2 handshake on
# every call. Classic mode would have performed like FPM; the gain is the worker, not the server.
#
# Worker mode is only safe because review has nothing that leaks across requests: every binding
# in AppServiceProvider is `bind` and not `singleton`, there are zero static properties in app/,
# zero `env()` calls outside config/, and nothing injects Request into a constructor. That was
# checked before this file was written, not assumed.

FROM composer:2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader \
      --ignore-platform-req=ext-grpc

COPY . .

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && DB_USERNAME=build-time-placeholder \
       DB_PASSWORD=build-time-placeholder \
       php artisan package:discover --ansi \
    && test -f vendor/autoload.php \
    && test -f bootstrap/cache/packages.php

FROM dunglas/frankenphp:php8.5@sha256:f92d81eb3fe4fd18b35d3d58192b7cc3acc8943817bbf39f2fcf0be02a3916dc AS final

RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y libpq-dev zlib1g-dev libzip-dev curl; \
    rm -rf /var/lib/apt/lists/*


# ORDER MATTERS. The grpc compile below is the expensive layer — twenty minutes — and every
# layer above it invalidates it when edited. Adding pcntl to the bundled-extension step, which
# used to sit here, threw away the whole grpc cache for a thirty-second change. Cheap and
# frequently-edited steps go AFTER it.
# The PECL extensions, in their own layer so a failure is attributable to them.
#
# MAKEFLAGS is load-bearing, and its value is bounded by MEMORY, not by core count.
#
# `pecl install` runs make single-threaded, and grpc vendors the entire gRPC C++ core — 735+
# translation units. It is not linking against a system libgrpc; there is no system libgrpc to
# link against. Serial, that measured ~40 minutes.
#
# `-j$(nproc)` was worse, not better. Each cc1plus compiling a gRPC core file holds 0.7-1.1 GB,
# so eight of them want ~7 GB on a 7.8 GB VM: available memory fell to 664 MB, swap filled to
# 99%, and the machine spent its time paging instead of compiling — 114 files in 24 minutes,
# slower per file than the serial build. Four fits.
RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y $PHPIZE_DEPS cmake git; \
    MAKEFLAGS="-j4" pecl install grpc; \
    MAKEFLAGS="-j4" pecl install protobuf; \
    docker-php-ext-enable grpc protobuf; \
    apt-get purge -y --auto-remove cmake git; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# `pdo` and `opcache` are compiled into PHP 8.5 already; asking for either builds nothing and
# then fails the install with `cp: cannot stat 'modules/*'`. pdo_pgsql and pcntl do need building.
#
# pcntl is not optional for Octane: InteractsWithServers handles SIGINT/SIGTERM to stop workers
# cleanly, and without the extension the constant does not exist — `octane:start` dies on
# `Undefined constant "Laravel\Octane\Commands\Concerns\SIGINT"` and the container restarts
# forever.
RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl; \
    rm -rf /var/lib/apt/lists/*

# The extensions have to be present in this image, not merely requested. Without this the build
# goes green and the failure surfaces as a 500 the first time a review touches an order — which
# is exactly how this service shipped before.
RUN php -r 'exit(extension_loaded("grpc") && extension_loaded("protobuf") ? 0 : 1);' \
    && php -r 'exit(class_exists("Grpc\\ChannelCredentials") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pcntl") && defined("SIGINT") ? 0 : 1);'

COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

WORKDIR /app

COPY --from=vendor /app /app

# Prove the install actually arrived. `artisan --version` is not used: it boots the framework
# and would demand the very credentials this image must not contain.
RUN test -f vendor/autoload.php \
    && test -f bootstrap/cache/packages.php \
    && test -f public/frankenphp-worker.php \
    && php -r 'require "vendor/autoload.php"; exit(class_exists("Laravel\\Octane\\OctaneServiceProvider") ? 0 : 1);'

# Explicit at runtime as well as in config: the two should never disagree, and this is the one
# a reader checks first.
ENV OCTANE_SERVER=frankenphp
ENV PORT=8002
EXPOSE 8002

RUN set -eux; \
    adduser --system --uid 10001 --group kinetix; \
    mkdir -p /data/caddy /config/caddy; \
    chown -R kinetix:kinetix /app /data /config

USER kinetix

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8002/api/health/ready > /dev/null || exit 1

# --max-requests recycles a worker after 500 requests. Review has no known leak, but a worker
# that lives forever turns any future slow leak into an outage rather than a blip.
CMD ["php", "artisan", "octane:start", \
     "--server=frankenphp", \
     "--host=0.0.0.0", \
     "--port=8002", \
     "--max-requests=500"]
