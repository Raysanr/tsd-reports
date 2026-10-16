<?php

namespace App\Support;

use Closure;
use WeakMap;

/** Per-web-request memo for small, repeatedly-read lookups (perf, explicit
 *  request 2026-10-15: "every page load is so slow" — Expected Income alone
 *  re-read projection_custom_rows ~340-415 times per load, Tally Checker
 *  product_groups 144x / products 128x / cost_breakdown_roles 72x).
 *
 *  Never stale, by construction:
 *  - Held against the current Request object in a WeakMap — freed with the
 *    request; the next request (incl. under Octane, where statics survive)
 *    always starts empty.
 *  - Flushed by AppServiceProvider on ANY insert/update/delete in the same
 *    request, so a row written earlier in a request is always seen by a
 *    later read (the reason customRows() was originally left uncached).
 *  - Disabled outside HTTP (artisan syncs/commands) — a long-running command
 *    must keep seeing values other processes change; unit tests keep it on
 *    so the real page path is what gets tested. */
class RequestMemo
{
    private static ?WeakMap $memo = null;

    public static function remember(string $key, Closure $compute): mixed
    {
        $request = self::request();
        if ($request === null) {
            return $compute();
        }

        self::$memo ??= new WeakMap();
        $bucket = self::$memo[$request] ?? [];
        if (!array_key_exists($key, $bucket)) {
            $bucket[$key] = $compute();
            self::$memo[$request] = $bucket;
        }

        return $bucket[$key];
    }

    public static function flush(): void
    {
        $request = self::request();
        if ($request !== null && self::$memo !== null) {
            unset(self::$memo[$request]);
        }
    }

    /** True inside a web request (memoizing is safe), false in artisan. */
    public static function active(): bool
    {
        return self::request() !== null;
    }

    private static function request(): ?object
    {
        $app = app();
        if (($app->runningInConsole() && !$app->runningUnitTests()) || !$app->bound('request')) {
            return null;
        }

        return $app->make('request');
    }
}
