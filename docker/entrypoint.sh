#!/bin/sh
set -e

# .dockerignore excludes the *contents* of these directories (only their
# .gitignore placeholders), which can make Docker's COPY skip creating the
# now-fully-empty directory entirely — recreate them so Laravel always has
# somewhere writable, regardless of what the build context happened to include.
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Scheduler service modes (2026-08-11) — replacing the external-pinger
# approach (cron-job.org hitting /cron/run) this app used on Render, which
# never got repointed during the Railway migration and left the whole
# scheduler silently dead:
#
#   schedule       one-shot `schedule:run`, for a Railway Cron Job service.
#                  Simple and cheap, but Railway enforces a 5-minute minimum
#                  interval — routes/console.php's 2-minute delta sync would
#                  only actually align (and so effectively run) every ~10
#                  minutes under this mode, since Laravel's scheduler only
#                  fires a job when the CURRENT minute matches its own cron
#                  expression, not "haven't run in a while, catch up now".
#   schedule:work  long-running `schedule:work`, for a normal persistent
#                  Railway service (no Cron Schedule set on it) instead of a
#                  Cron Job one. Laravel's own loop checks every minute
#                  internally — no external trigger of any kind, and no
#                  5-minute floor, so every job in routes/console.php
#                  (including the 2-minute delta sync) runs on its actual
#                  configured interval, full fidelity with the pre-migration
#                  design. Costs a small always-on container instead of a
#                  briefly-spun-up one every 5 minutes — the tradeoff for
#                  not being capped at 5-minute granularity.
#
# Both skip config:cache/route:cache/view:cache/migrate below — those exist
# for the long-running WEB server, where paying that cost once per boot is
# worth it; a one-shot artisan command re-paying it every invocation (mode 1)
# or a worker that only needs it once at its own single startup anyway
# (mode 2, no benefit to Laravel's file-based cache surviving a restart it
# never has here) gets nothing from it (Laravel falls back to reading
# .env/config directly with no cache present — a performance optimization,
# not a requirement) — and both skip the self-check block too, since that
# needs a running HTTP server and neither of these is one.
if [ "$1" = "schedule" ]; then
    exec php artisan schedule:run
fi

if [ "$1" = "schedule:work" ]; then
    exec php artisan schedule:work
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
