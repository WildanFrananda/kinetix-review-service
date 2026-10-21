FROM dunglas/frankenphp:1.13.1-php8.5.12-trixie@sha256:55c63014b845d24c3986b72ce7fdd1bdbb3c9219a1ecdc37bf828c7f038e6f1a

RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y libpq-dev zlib1g-dev libzip-dev curl; \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y $PHPIZE_DEPS cmake git; \
    MAKEFLAGS="-j4" pecl install grpc-1.84.0; \
    MAKEFLAGS="-j4" pecl install protobuf-5.36.1; \
    docker-php-ext-enable grpc protobuf; \
    apt-get purge -y --auto-remove cmake git; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y $PHPIZE_DEPS; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl; \
    rm -rf /var/lib/apt/lists/*

RUN php -r 'exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("grpc") && extension_loaded("protobuf") ? 0 : 1);' \
    && php -r 'exit(class_exists("Grpc\\ChannelCredentials") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pcntl") && defined("SIGINT") ? 0 : 1);' \
    && php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
