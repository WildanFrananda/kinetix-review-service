# The PHP runtime review's image starts from: FrankenPHP plus the native extensions.
#
# Split out of the service Dockerfile because building it costs 29-52 minutes and that cost was
# being paid for edits that had nothing to do with it. `pecl install grpc` vendors the entire
# gRPC C++ core — 735+ translation units — and compiles it from source; there is no system
# libgrpc to link against. Two builds in one afternoon spent 81 minutes between them, every
# minute of it recompiling bytes that had not changed.
#
# Layer ordering inside the service Dockerfile was already correct, and is not what this fixes.
# The problem is that the layer HAS to be rebuilt whenever anyone touches it, and while iterating
# on that file you touch it constantly. Here, it is touched when the extension list changes and
# at no other time.
#
# Built and pushed by .gitlab-ci.yml's `php-runtime` job, which runs only when this file changes.
# The service Dockerfile pins the result by digest, so a tag moving underneath cannot change what
# review is built from.

FROM dunglas/frankenphp:php8.5@sha256:f92d81eb3fe4fd18b35d3d58192b7cc3acc8943817bbf39f2fcf0be02a3916dc

# Runtime libraries the extensions link against, and curl for the container healthcheck.
RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y libpq-dev zlib1g-dev libzip-dev curl; \
    rm -rf /var/lib/apt/lists/*

# MAKEFLAGS is load-bearing, and its value is bounded by MEMORY, not by core count.
#
# `pecl install` runs make single-threaded, and serial that measured ~40 minutes. `-j$(nproc)`
# was worse, not better: each cc1plus compiling a gRPC core file holds 0.7-1.1 GB, so eight of
# them want ~7 GB on a 7.8 GB VM — available memory fell to 664 MB, swap filled to 99%, and the
# machine paged instead of compiling. Four fits.
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
#
# Asserted here rather than downstream so a broken runtime never reaches a service build.
RUN php -r 'exit(extension_loaded("grpc") && extension_loaded("protobuf") ? 0 : 1);' \
    && php -r 'exit(class_exists("Grpc\\ChannelCredentials") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pcntl") && defined("SIGINT") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
