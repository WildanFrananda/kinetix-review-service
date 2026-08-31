FROM php:8.5-cli-alpine

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader 2>/dev/null || true

ENV PORT=8002
EXPOSE 8002

# Run as an unprivileged user (S1 / P0-IMG-01).
RUN adduser -D -u 10001 kinetix && chown -R kinetix:kinetix /var/www/html
USER kinetix

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8002"]
