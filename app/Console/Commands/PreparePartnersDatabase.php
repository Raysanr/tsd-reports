<?php

namespace App\Console\Commands;

use App\Support\DataSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the Partners Data Management store if it doesn't exist yet
 * (DataSet::partnersConnectionConfig()), so `migrate --database=partners`
 * can run. Safe to run on every deploy.
 */
class PreparePartnersDatabase extends Command
{
    protected $signature = 'partners:prepare-db';

    protected $description = 'Create the Partners Data Management database/schema if missing';

    public function handle(): int
    {
        $config = config('database.connections.' . DataSet::PARTNERS);
        $main = DB::connection(DataSet::mainConnection());

        match ($config['driver']) {
            'sqlite' => $config['database'] === ':memory:' || file_exists($config['database']) || touch($config['database']),
            'pgsql'  => $main->statement('CREATE SCHEMA IF NOT EXISTS "' . str_replace('"', '', $config['search_path']) . '"'),
            default  => $main->statement('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $config['database']) . '`'),
        };
        $this->info('Partners database ready.');

        return self::SUCCESS;
    }
}
