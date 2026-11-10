# Habi production image (SDD §12, N6): the same PHP built-in server and
# front controller as local development, with production dependencies only.
FROM php:8.2-cli

# Composer needs unzip to extract dist archives; nothing else is required:
# the official image already provides pdo_sqlite, mbstring, zlib and fileinfo.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first so code changes reuse the cached vendor layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p data

# Mount persistent storage at /app/data (VM bind mount / Render disk): it
# holds app.sqlite and administrator uploads. The first request on an empty
# volume migrates and seeds once; later boots never reseed.
ENV APP_ENV=production \
    DB_DSN=sqlite:/app/data/app.sqlite \
    PORT=8080 \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080

# public/index.php doubles as the router script so dotted paths such as
# /uploads/<name>.png reach the application (see the comment there).
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT} -t public public/index.php"]
