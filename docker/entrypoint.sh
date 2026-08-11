#!/bin/sh
set -e

# .dockerignore excludes the *contents* of these directories (only their
# .gitignore placeholders), which can make Docker's COPY skip creating the
# now-fully-empty directory entirely — recreate them so Laravel always has
# somewhere writable, regardless of what the build context happened to include.
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Cron Job service mode (2026-08-11): a second Railway service ("tsd-reports-
# scheduler") runs this SAME image on a `* * * * *` schedule with Custom
# Start Command set to the single word `schedule`, replacing the external-
# pinger approach (cron-job.org hitting /cron/run) this app used on Render,
# which never got repointed during the Railway migration and left the whole
# scheduler silently dead. Deliberately skips config:cache/route:cache/
# view:cache/migrate below — those exist for the long-running web server,
# where paying that cost once per boot is worth it; here Railway spins up a
# brand-new container every single minute, so re-paying it every run would
# be pure waste for zero benefit (Laravel falls back to reading .env/config
# directly with no cache present — a performance optimization, not a
# requirement) — and skips the self-check block too, since that needs a
# running HTTP server and this is a one-shot artisan command, not one.
if [ "$1" = "schedule" ]; then
    exec php artisan schedule:run
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Self-diagnostic: after the server has had a moment to boot, request the
# health-check path ourselves and print the status code (plus the response
# body when it isn't a 200) straight into Render's logs — Render's own
# health checker only reports "timed out", never WHY the page failed.
(
    sleep 8
    code=$(curl -s -o /tmp/selfcheck.html -w '%{http_code}' "http://127.0.0.1:${PORT:-8080}/login" || echo 'curl-failed')
    echo "[self-check] GET /login -> ${code}"
    if [ "$code" != "200" ]; then
        echo "[self-check] response body (first 800 bytes):"
        head -c 800 /tmp/selfcheck.html
        echo ""
    fi
) &

# PHP's built-in server (what `artisan serve` wraps) handles exactly ONE
# request at a time unless this is set — confirmed as the root cause of a
# real incident (2026-08-11): a single slow admin action (Sync Health's "Fix
# Now") made every other route return 499 for every user for its entire
# duration, because nothing else could be served while it ran. This alone
# doesn't make any individual request faster, but it stops one slow request
# from taking the whole app down for everyone else. Still a stopgap, not a
# real production server (no php-fpm/nginx — see the Dockerfile's own
# comment on this being free-tier-right-sized, not high-traffic-ready);
# revisit if concurrent load ever outgrows a handful of workers.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

# Render injects $PORT at runtime; 8080 is only a local-testing fallback.
exec php artisan serve --host 0.0.0.0 --port "${PORT:-8080}"
