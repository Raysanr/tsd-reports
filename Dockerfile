# Multi-stage build: composer/npm still run in a plain PHP+Node builder
# stage (unchanged from before), the FINAL runtime image is FrankenPHP
# instead of `php artisan serve` (explicit request, 2026-09-17: moving off
# the PHP built-in dev server — confirmed via live Railway logs that many
# requests, including trivial ones like /calls/api/own-status, were
# clustering at exact multiples of ~500ms, consistent with requests queuing
# behind the dev server's hard 4-worker cap, not real processing time; see
# docker/entrypoint.sh's own 2026-08-11 incident comment for the same root
# cause's first, smaller-scope symptom). FrankenPHP (github.com/dunglas/
# frankenphp) is a real, actively-maintained production PHP app server
# built on Caddy — genuine concurrency, no separate nginx config to
# maintain, and a much smaller Dockerfile/entrypoint diff than php-fpm+
# nginx would need. Started in classic (non-worker) mode deliberately, not
# worker mode: worker mode keeps the whole Laravel app booted in memory
# between requests for lower latency/cost, but needs every stateful
# singleton/static property audited first to avoid request-to-request
# state bleed — a real, separate risk class not worth taking on the SAME
# deploy as the server swap itself, especially after a past outage tied to
# a server-capacity change (docker/entrypoint.sh's own comment).
#
# Switched to worker mode 2026-09-28 (explicit request: reduce Railway's
# Memory/CPU cost with zero functional change, confirmed live via
# `railway metrics` this app runs at real, sustained traffic — ~75 req/min
# — where classic mode's full-framework-reboot-per-request cost is real,
# repeated CPU work). The audit this comment named as a prerequisite was
# done first (static properties, singleton bindings, globals, cached auth/
# session state — none found), then verified live: two distinct logged-in
# users hammering the worker-mode server with genuinely concurrent
# requests never saw each other's session data. See docker/entrypoint.sh's
# own doc comment on its `octane:frankenphp` invocation for the mechanics.
FROM php:8.2-cli AS builder

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
# can't be cached the same way). Unaffected by the FrankenPHP move — this
# whole builder stage is discarded after the runtime stage below copies
# out only what it needs (vendor/, public/build/, the app source itself),
# same caching behavior as before.
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

# Runtime stage — FrankenPHP, pinned to an exact version (not the floating
# `1-php8.2` tag) for the same "reproducible build" reasoning every other
# pinned dependency in this repo already follows. install-php-extensions
# (baked into every FrankenPHP image) resolves the matching OS packages for
# pdo_pgsql/zip/gd on its own — no manual apt-get of dev headers needed the
# way the builder stage above still requires for its own separate PHP CLI
# install.
FROM dunglas/frankenphp:1.12.7-php8.2 AS runtime

# pcntl added 2026-09-28 (worker-mode switch) — root-caused live via a
# failed Railway deploy: `octane:frankenphp` crashed on every boot attempt
# with "Undefined constant Laravel\Octane\Commands\Concerns\SIGINT". Octane
# subscribes to OS signals (SIGINT/SIGTERM/etc., for graceful worker
# shutdown/restart) via PHP's pcntl extension, which defines those signal
# constants — without pcntl loaded at all, PHP can't resolve the constant
# name, not just skip the feature. Confirmed safe: Railway's own zero-
# downtime deploy kept the previous successful deployment serving all live
# traffic (0% error rate throughout) while this one failed its health
# check and was discarded, so this was caught before ever reaching a real
# user.
RUN install-php-extensions pdo_pgsql zip gd pcntl

# curl — used by entrypoint.sh's own self-check block (curls its own
# /login right after boot) and not guaranteed present on this base image
# the way it was on the old php:8.2-cli one (installed there implicitly
# via apt-get for other reasons). Cheap, removes the doubt either way.
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# memory_limit raised from PHP's 128M default (root-caused live via Railway
# logs, 2026-10-08: repeated "Allowed memory size of 134217728 bytes
# exhausted" FatalErrors in Collection.php/Carbon internals, no consistent
# route — consistent with worker-mode's long-lived processes (see
# entrypoint.sh's own --max-requests=500 comment) gradually accruing memory
# across hundreds of requests until whichever request lands next tips a
# worker over 128M, not a leak specific to any one page). 256M buys real
# headroom at this app's actual traffic/payload sizes without masking a
# genuine unbounded-growth bug the way going much higher would.
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/memory-limit.ini

WORKDIR /app

# Excludes the builder stage's own node_modules (npm/Vite are a build-time
# tool only — the built output is the static public/build/ assets, nothing
# at runtime ever requires Node or npm's installed packages) — keeps the
# final image meaningfully smaller than a blanket `COPY --from=builder /app
# /app` would. .git/vendor's dev-only composer.lock etc. never made it into
# the builder stage to begin with (.dockerignore already excludes those
# from the build CONTEXT, separate from this COPY).
COPY --from=builder /app /app
RUN rm -rf /app/node_modules

# docker/Caddyfile intentionally not copied anymore (2026-09-28, worker
# mode switch — see entrypoint.sh's own doc comment): Octane's
# octane:frankenphp generates its own Caddyfile internally now. The file
# itself stays in the repo as a reference/rollback path to classic mode,
# it's just no longer read at runtime.
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
