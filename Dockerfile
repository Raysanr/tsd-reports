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

# Dependency manifests copied BEFORE the rest of the source (explicit
# follow-up request, 2026-09-04: "why is it so much now slow to deploy" —
# root-caused: the old single `COPY . .` before install meant Docker's layer
# cache invalidated on ANY file change, forcing a full composer install +
# npm ci + npm run build (~12+ min) on every single push, even a blade-only
# or JS-only change with zero dependency changes. Splitting the copy this
# way means Docker only re-runs composer install/npm ci when composer.lock/
# package-lock.json themselves actually change — a normal code-only push
# reuses the cached dependency layer entirely and only re-runs the fast
# `npm run build` step (Vite needs the real resources/ present, so that part
# can't be cached the same way).
#
# --no-scripts on this first composer install is required, not optional:
# composer.json's post-autoload-dump hook runs `artisan package:discover`,
# which needs the actual app/ + bootstrap/ + config/ present to boot
# Laravel at all — running it before COPY . . below would fail outright.
# The second, scripted install after the full COPY re-runs those hooks now
# that the app exists, and is itself fully cached whenever composer.lock is
# unchanged (Docker sees the same COPY input + same RUN command).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm run build \
    && npm prune --omit=dev

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
