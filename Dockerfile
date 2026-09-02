FROM composer:2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
      --no-dev \
      --no-scripts \
      --no-interaction \
      --prefer-dist \
      --optimize-autoloader

COPY . .

RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
    && DB_USERNAME=build-time-placeholder \
       DB_PASSWORD=build-time-placeholder \
       php artisan package:discover --ansi \
    && test -f vendor/autoload.php \
    && test -f bootstrap/cache/packages.php

FROM php:8.5-cli-alpine@sha256:763e2dc50d4b0cf8d02a1d8fbeedd43f9be879c0be928b1d6f247d45c81fa28f AS final

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# Prove the install actually arrived. `artisan --version` is not used: it boots the framework
# and would demand the very credentials this image must not contain.
RUN test -f vendor/autoload.php \
    && test -f bootstrap/cache/packages.php \
    && php -r 'require "vendor/autoload.php"; exit(class_exists("Illuminate\\Foundation\\Application") ? 0 : 1);' 

ENV PORT=8002
EXPOSE 8002

RUN adduser -D -u 10001 kinetix && chown -R kinetix:kinetix /var/www/html
USER kinetix

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8002"]
