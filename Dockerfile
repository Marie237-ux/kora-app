FROM php:8.3-cli

# System libraries: PostgreSQL client, image formats for gd (JPEG, PNG, WebP), zip for Composer.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libpq-dev libonig-dev libxml2-dev \
    libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring exif pcntl bcmath gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Works whatever the build context is: the repository root (services/api inside it) or services/api itself.
COPY . /src
RUN if [ -f /src/services/api/composer.json ]; then mv /src/services/api /app; else mv /src /app; fi \
    && rm -rf /src

WORKDIR /app
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV LOG_CHANNEL=stderr \
    PHP_CLI_SERVER_WORKERS=4

# Online demo for now: test catalogue loaded on start, test-data banner on the site.
# Set these to false (Render > kora-api > Environment) before the real launch.
ENV KORA_DEMO=true \
    KORA_DEMO_REMOTE=true \
    KORA_DEMO_SEED=true

# Render gives the port in $PORT and the public address in $RENDER_EXTERNAL_URL. Migrations run on every start.
# With KORA_DEMO_SEED=true the demo catalogue (shops, products, images, CMS pages) is reloaded on each start:
# free Render instances lose their files when they restart, so images are re-created from the repository.
CMD ["sh", "-c", "export APP_URL=${RENDER_EXTERNAL_URL:-$APP_URL}; php artisan migrate --force && php artisan config:cache && if [ \"$KORA_DEMO_SEED\" = true ]; then php artisan kora:demo-storefront database/demo/storefront.json --fresh || true; fi; php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
