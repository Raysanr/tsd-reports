<?php

namespace App\Http\Controllers\DataManagement;

use App\Http\Controllers\Controller;
use App\Services\PartnerFeed;
use App\Services\PartnerSync;
use App\Support\DataSet;
use Illuminate\Support\Facades\Cache;

/** "Sync now" (explicit request, 2026-11-03): a TSA just added in a partner
 *  app shows up right away instead of waiting for the 10-minute partners:sync. */
class PartnerSyncController extends Controller
{
    public function store(PartnerFeed $feed, PartnerSync $sync)
    {
        abort_unless(DataSet::isPartners(), 404);

        if (!$feed->configured()) {
            return back()->with('error', 'Partner sync is not set up yet (PARTNER_FEED_SECRET missing).');
        }

        $lock = Cache::lock('partners-sync-now', 120);
        if (!$lock->get()) {
            return back()->with('error', 'A sync is already running — try again in a minute.');
        }

        try {
            $sync->removeUnsynced();
            $failed = [];
            foreach (array_keys(PartnerFeed::partners()) as $slug) {
                try {
                    $sync->sync($slug);
                } catch (\Throwable $e) {
                    report($e);
                    $failed[] = PartnerFeed::name($slug);
                }
            }
        } finally {
            $lock->release();
        }

        return $failed === []
            ? back()->with('success', 'Synced — latest products, TSAs and orders from PEAK.CO, M&J and MLC.')
            : back()->with('error', 'Could not sync ' . implode(', ', $failed) . ' — the others are up to date.');
    }
}
