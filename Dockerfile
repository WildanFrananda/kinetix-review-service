FROM composer:2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

COPY bin ./bin

RUN apk add --no-cache git protobuf protobuf-dev \
  && sh bin/sync-contracts \
  && mkdir -p generated \
  && protoc --php_out=generated -I .contracts/proto \
       .contracts/proto/order/v1/order.proto .contracts/proto/common/v1/common.proto \
       .contracts/proto/review/v1/review.proto \
  && apk del protobuf protobuf-dev

COPY generated/Order/V1/OrderServiceClient.php ./generated/Order/V1/OrderServiceClient.php
COPY generated/Review/V1/ReviewEvidenceServiceInterface.php ./generated/Review/V1/ReviewEvidenceServiceInterface.php

RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader \
      --ignore-platform-req=ext-grpc \
      --ignore-platform-req=ext-sockets

COPY . .

RUN rm -rf rr bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && DB_CONNECTION=build-time-placeholder php artisan package:discover --ansi \
    && test -f vendor/autoload.php \
    && test -f bootstrap/cache/packages.php

FROM golang:1.26-bookworm@sha256:d9c68c2c51161e12fd77e4c6320687c9cd86e1af1e3ad6e6cd63ff970641453c AS rr

WORKDIR /src

COPY rr/go.mod rr/go.sum ./

RUN go mod download

COPY rr/ ./

RUN CGO_ENABLED=0 go build -trimpath -buildvcs=false -ldflags="-s -w" -o /out/rr .

FROM ghcr.io/wildanfrananda/kinetix-review-php-runtime@sha256:457296f75eabfa2765567792509ed04e85ddc2d40c9b13a1ca3361a3368f110d AS final

RUN set -eux; \
    apt-get update; \
    apt-get upgrade -y --no-install-recommends; \
    rm -rf /var/lib/apt/lists/*

COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php-hardening.ini /usr/local/etc/php/conf.d/zz-hardening.ini

WORKDIR /app

COPY --from=vendor /app /app

COPY --from=rr /out/rr /usr/local/bin/rr

RUN set -eu; \
    mkdir -p storage/logs storage/framework/cache/data storage/framework/metrics; \
    for f in vendor/autoload.php bootstrap/cache/packages.php public/frankenphp-worker.php grpc-worker.php .rr.yaml \
             .contracts/proto/review/v1/review.proto /usr/local/bin/rr; do \
      test -f "$f" || { echo "missing from the image: $f"; exit 1; }; \
    done; \
    php -r 'require "vendor/autoload.php"; exit(class_exists("Laravel\\Octane\\OctaneServiceProvider") ? 0 : 1);' \
      || { echo "vendor/autoload.php loads but Laravel\\Octane\\OctaneServiceProvider is not on the classmap"; exit 1; }; \
    php -r 'exit(ini_get("session.upload_progress.enabled") ? 1 : 0);' \
      || { echo "session.upload_progress.enabled is on"; exit 1; }

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

CMD ["php", "artisan", "octane:start", \
     "--server=frankenphp", \
     "--host=0.0.0.0", \
     "--port=8002", \
     "--max-requests=500", \
     "--log-level=WARN"]
