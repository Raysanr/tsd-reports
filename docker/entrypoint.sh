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
    # A sub-minute pancake:sync-leads loop used to be started HERE (explicit
    # request, 2026-09-12: "make it like realtime displaying in the leads
    # when there's new in the POS"), but it never actually ran: Railway's
    # upbeat-light service is configured with a custom start command of
    # `php artisan schedule:work` directly, which bypasses this whole script
    # — confirmed via `railway ssh --service upbeat-light -- cat
    # /proc/1/cmdline`, which showed schedule:work as PID 1 itself, not a
    # child of entrypoint.sh. That's the same class of mistake as the
    # earlier dead CronController::run() /cron/run fix (see git history):
    # correct in isolation, never actually invoked in this deployment.
    # Moved to app/Providers/AppServiceProvider.php's boot() instead, which
    # runs inside the schedule:work PHP process itself no matter how
    # Railway launches it — see that file's own comment for the loop code
    # and reasoning.
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

# FrankenPHP (explicit request, 2026-09-17 — see the Dockerfile's own doc
# comment for the full reasoning) replaces `php artisan serve` as the web
# server. That server handled exactly ONE request at a time unless
# PHP_CLI_SERVER_WORKERS was set — confirmed as the root cause of a real
# incident (2026-08-11): a single slow admin action (Sync Health's "Fix
# Now") made every other route return 499 for every user for its entire
# duration. Bumping that worker count to 4 was a stopgap, not a fix — live
# Railway logs (2026-09-17) still showed many requests, including trivial
# ones, clustering at exact multiples of ~500ms, consistent with requests
# still queuing behind that same hard worker cap. FrankenPHP is a real
# production PHP app server (built on Caddy) with genuine concurrency, no
# fixed worker ceiling to hit. docker/Caddyfile reads $PORT itself (Railway
# injects it at container start) — nothing to pass here.
exec frankenphp run --config /etc/frankenphp/Caddyfile
