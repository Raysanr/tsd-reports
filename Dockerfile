# Single-container deploy for Render's free tier: PHP built-in server behind
# Render's own TLS-terminating proxy. Not a high-traffic production setup
# (no php-fpm/nginx), but this is an internal telesales dashboard on a free
# plan — right-sized for that, not over-built for load it'll never see.
FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        git unzip libpq-dev libzip-dev libpng-dev \
    && docker-php-ext-install pdo_pgsql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node, for the Vite production build (resources/js, resources/css).
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

WORKDIR /app
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm ci \
    && npm run build \
    && npm prune --omit=dev

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
