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
    # Sub-minute leads loop (explicit request, 2026-09-12: "make it like
    # realtime displaying in the leads when there's new in the POS" — the
    # Call Tracker Leads page needs a new Pancake order to reach it faster
    # than schedule:work's own floor allows). Laravel's scheduler has no
    # ->everySeconds() at all — the shortest interval expressible in
    # routes/console.php is once a minute — so pancake:sync-leads can never
    # run more often than that through schedule:work no matter how it's
    # configured there. This plain shell loop is the only way to get a
    # genuinely sub-minute cadence: it runs pancake:sync-leads directly,
    # sleeps LEADS_LOOP_INTERVAL seconds (default 15, matching the Leads
    # page's own 15s poll in resources/js/calls.js), and repeats forever, as
    # a background process alongside schedule:work in the same container —
    # every OTHER scheduled job (order sync, reconciliation, etc.) still
    # runs through schedule:work's normal once-a-minute loop, unaffected.
    #
    # An earlier attempt at this same goal (2026-09-12, still in this file's
    # git history) piggybacked on CronController::run()'s /cron/run
    # endpoint, reasoning that an external pinger already hit it once a
    # minute and could be reconfigured to hit it every 15-20s instead. That
    # never actually helped: this deployment stopped using an external
    # pinger entirely back on 2026-08-11 (see this file's own "Scheduler
    # service modes" comment above) in favor of schedule:work, so nothing
    # was left calling /cron/run at all — that fix was correct in isolation
    # but dead code in THIS deployment's actual configuration. Confirmed
    # live via `railway logs --service upbeat-light`: pancake:sync-leads was
    # still firing at exactly :00-:02 of every minute, one run per minute,
    # matching schedule:work's own floor exactly, not the faster cadence
    # that fix intended.
    #
    # pancake:sync-leads already has its own self-contained overlap lock
    # (pancake_sync_leads_running, see that class's own doc comment) that
    # doesn't depend on Schedule::withoutOverlapping() — so it's already
    # safe for this loop to fire well inside a previous run's own duration
    # without ever double-running.
    (
        while true; do
            php artisan pancake:sync-leads >> storage/logs/leads-loop.log 2>&1
            sleep "${LEADS_LOOP_INTERVAL:-15}"
        done
    ) &

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
