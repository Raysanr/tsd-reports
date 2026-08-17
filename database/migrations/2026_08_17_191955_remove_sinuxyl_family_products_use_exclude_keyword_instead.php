<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Undoes the 3 Sinuxyl-family product rows the seed_real_pancake_product_ids
 * migration added earlier today (Steam Pack, Roll On Oil Relief, Nasal
 * Inhaler) — explicit request (2026-08-17): SINUXYL only, no separate rows
 * for its siblings. That migration's own goal (don't let SINUXYL's report
 * row silently absorb these genuinely separate real Pancake products) is now
 * met a different way — the match_exclude_keyword migration right after
 * this one's sibling migration gives SINUXYL an explicit exclude list
 * (STEAM PACK, ROLL ON, NASAL INHALER, NASAL SPRAY) — so these 3 rows are no
 * longer needed for correctness, just for visibility the user doesn't want.
 *
 * Hard delete, not soft — the user already manually removed these 3 via the
 * admin panel (product.bulk_delete, 2026-08-17 19:01:50) before this
 * migration existed; this makes that the same real outcome on every
 * environment (a fresh migrate, or Railway once this deploys) instead of
 * depending on a manual admin action having already happened there too.
 */
return new class extends Migration
{
    private const DISPLAY_NAMES = [
        'SINUXYL STEAM PACK',
        'SINUXYL ROLL ON OIL RELIEF',
        'SINUXYL NASAL INHALER',
    ];

    public function up(): void
    {
        Product::withTrashed()->whereIn('display_name', self::DISPLAY_NAMES)->forceDelete();
    }

    public function down(): void
    {
        // Not reconstructed — these rows carried no data worth preserving
        // beyond what the sibling migration above already recreates from its
        // own constants if it's ever re-run.
    }
};
