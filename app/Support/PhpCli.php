<?php

namespace App\Support;

/**
 * The PHP command-line program for starting background artisan commands
 * (Sync Now, the cron pinger, manual Pancake syncs). On FrankenPHP/Octane,
 * PHP_BINARY is the FrankenPHP server itself, so `PHP_BINARY artisan ...`
 * silently did nothing and the button only spun briefly (2026-11-12).
 */
class PhpCli
{
    private const FALLBACK_PATHS = ['/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php', '/usr/local/opt/php/bin/php'];

    public static function binary(): string
    {
        if (self::isPhpCli(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $found = trim((string) @shell_exec('command -v php 2>/dev/null'));
        if ($found !== '' && is_executable($found)) {
            return $found;
        }

        foreach (self::FALLBACK_PATHS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return 'php';
    }

    /** "php", "php8.2", "php82" — not "frankenphp" or "php-fpm". */
    public static function isPhpCli(string $path): bool
    {
        return $path !== '' && preg_match('/^php[\d.]*$/i', basename($path)) === 1;
    }
}
