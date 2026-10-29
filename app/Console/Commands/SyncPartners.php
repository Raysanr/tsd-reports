<?php

namespace App\Console\Commands;

use App\Services\PartnerFeed;
use App\Services\PartnerSync;
use Illuminate\Console\Command;

/** Pulls PEAK.CO / M&J / MLC data into Partners Data Management (PartnerSync). */
class SyncPartners extends Command
{
    protected $signature = 'partners:sync {partner? : peak-co, mj or mlc — all when left out}';

    protected $description = 'Sync partner products, TSAs and orders into Partners Data Management';

    public function handle(PartnerFeed $feed, PartnerSync $sync): int
    {
        if (!$feed->configured()) {
            $this->warn('PARTNER_FEED_SECRET is not set — nothing synced.');

            return self::SUCCESS;
        }

        $slugs = $this->argument('partner') ? [$this->argument('partner')] : array_keys(PartnerFeed::partners());
        $failed = false;
        foreach ($slugs as $slug) {
            if (!isset(PartnerFeed::partners()[$slug])) {
                $this->error("Unknown partner: {$slug}");

                return self::FAILURE;
            }
            try {
                $counts = $sync->sync($slug);
                $this->info(PartnerFeed::name($slug) . ": {$counts['products']} products, {$counts['tsas']} TSAs, {$counts['orders']} orders updated.");
            } catch (\Throwable $e) {
                // One partner being down must not stop the others.
                $failed = true;
                report($e);
                $this->error(PartnerFeed::name($slug) . ': ' . $e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
