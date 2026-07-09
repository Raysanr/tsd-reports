#!/bin/sh
set -e

# .dockerignore excludes the *contents* of these directories (only their
# .gitignore placeholders), which can make Docker's COPY skip creating the
# now-fully-empty directory entirely — recreate them so Laravel always has
# somewhere writable, regardless of what the build context happened to include.
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

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

# Render injects $PORT at runtime; 8080 is only a local-testing fallback.
exec php artisan serve --host 0.0.0.0 --port "${PORT:-8080}"
