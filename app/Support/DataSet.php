<?php

namespace App\Support;

use App\Services\PartnerFeed;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Which data the Data Management module is working on.
 *
 * /data/...     — TSD's own (main database).
 * /partners/... — Partners Data Management (requested 2026-10-29): the very
 *   same pages, controllers and calculations, run on a separate "partners"
 *   database. Its products, TSAs and orders are synced from the PEAK.CO, M&J
 *   and MLC apps (PartnerSync), each tagged with the partner's name as its
 *   team, so the usual team filter becomes ALL / PEAK.CO / M&J / MLC. Every
 *   cost, projection and Cost Breakdown figure is typed and stored here.
 *
 * Users and the Activity Log always stay on the main database.
 */
class DataSet
{
    public const PARTNERS = 'partners';

    private static ?string $mainConnection = null;

    private static bool $partners = false;

    /** Called once at boot, before anything can switch the default. */
    public static function boot(): void
    {
        self::$mainConnection = config('database.default');
        config(['database.connections.' . self::PARTNERS => self::partnersConnectionConfig()]);
        URL::defaults(['dm' => 'data']);
    }

    public static function mainConnection(): string
    {
        return self::$mainConnection ?? config('database.default');
    }

    public static function isPartners(): bool
    {
        return self::$partners;
    }

    /** Switches this request to the partners database and partner teams. */
    public static function enterPartners(): void
    {
        self::$partners = true;
        DB::setDefaultConnection(self::PARTNERS);
        config(['teams' => self::partnerTeams()]);
        URL::defaults(['dm' => 'partners']);
    }

    /** Start of every request: Octane workers keep statics between requests. */
    public static function reset(): void
    {
        if (self::$partners) {
            self::leavePartners();
        }
    }

    /** Back to TSD's own data. */
    public static function leavePartners(): void
    {
        self::$partners = false;
        DB::setDefaultConnection(self::mainConnection());
        config(['teams' => require config_path('teams.php')]);
        URL::defaults(['dm' => 'data']);
    }

    /** Runs $callback on the partners database, then switches back. */
    public static function onPartners(\Closure $callback): mixed
    {
        $was = self::$partners;
        self::enterPartners();
        try {
            return $callback();
        } finally {
            if (!$was) {
                self::leavePartners();
            }
        }
    }

    /** Same shape as config('teams'): one team per partner, named after it. */
    public static function partnerTeams(): array
    {
        return collect(PartnerFeed::partners())
            ->map(fn (array $p) => ['name' => $p['name'], 'order_team' => $p['name']])
            ->all();
    }

    /** "/data/<path>" or "/partners/<path>", whichever is in use. */
    public static function url(string $path): string
    {
        return url((self::$partners ? 'partners' : 'data') . '/' . ltrim($path, '/'));
    }

    /**
     * The default connection's settings, pointed at a separate store:
     * SQLite — a sibling file (or its own :memory: database in tests);
     * MySQL/MariaDB — a "<database>_partners" database; Postgres — a
     * "partners" schema in the same database.
     */
    public static function partnersConnectionConfig(): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration(
            config('database.connections.' . config('database.default'), [])
        );
        unset($config['url']);

        switch ($config['driver'] ?? null) {
            case 'sqlite':
                $db = (string) ($config['database'] ?? '');
                $config['database'] = env('PARTNERS_DB_DATABASE', $db === ':memory:' || $db === ''
                    ? ':memory:'
                    : preg_replace('/(\.sqlite)?$/', '_partners.sqlite', $db, 1));
                break;
            case 'pgsql':
                $config['search_path'] = env('PARTNERS_DB_SCHEMA', 'partners');
                break;
            default:
                $config['database'] = env('PARTNERS_DB_DATABASE', ($config['database'] ?? 'tsd') . '_partners');
        }

        return $config;
    }
}
