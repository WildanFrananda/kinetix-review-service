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
# Worker mode is safe here not because nothing is shared but because nothing shared holds request
# state. Two bindings in AppServiceProvider are `singleton` on purpose — the order gRPC client and
# the token verifier — and the order client is additionally warmed in config/octane.php, so it
# survives Octane's per-request container clone instead of living one request. That sharing is the
# point twice over: it is what reuses the HTTP/2 channel, and it is what lets the circuit breaker
# inside GrpcOrderClient accumulate failures instead of resetting before it could ever open.
# Neither object stores anything belonging to a caller, and nothing injects Request into a
# constructor. Do not "fix" these back to `bind`.

FROM composer:2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# The generated protobuf classes are on the classmap, so they must exist before the first
# `composer install` builds an optimized autoloader — without them it fails outright with
# "Could not scan for classes inside generated/". Copied here rather than moved into the later
# `COPY . .` so the dependency layer still caches on composer.json alone.
COPY bin ./bin

# The wire contracts and the PHP classes generated from them. Fetched and generated rather than
# committed: this repository tracks no .proto, which is what S9 measures.
# apk, not apt-get: the vendor stage is composer:2, which is Alpine.
RUN apk add --no-cache git protobuf \
  && sh bin/sync-contracts \
  && mkdir -p generated \
  && protoc --php_out=generated -I .contracts/proto \
       .contracts/proto/order/v1/order.proto .contracts/proto/common/v1/common.proto \
  && apk del protobuf

# The one hand-written stub: `grpc_php_plugin` is a separate binary and the generated service
# client is fifteen lines of _simpleRequest.
COPY generated/Order/V1/OrderServiceClient.php ./generated/Order/V1/OrderServiceClient.php

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

# The PHP runtime — FrankenPHP plus grpc, protobuf, pdo_pgsql and pcntl — is built by
# docker/php-runtime.Dockerfile and published by the php-runtime CI job. Pinned by digest:
# a tag is a moving pointer, and the extension set is exactly the thing that must not move
# under a service without somebody deciding it should.
FROM registry.gitlab.com/wildanfrananda/kinetix-review-service/php-runtime@sha256:883de4f70a224a6264066cbe4ca22793fae80044dcbd46f0e1d355116f0ff730 AS final

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
