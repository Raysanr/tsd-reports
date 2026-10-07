<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** DSPPR - TSM Report's own "TIKTOK ORDERS" row, one per day — see the
 *  create_dsppr_tiktok_entries_table migration's own doc comment for why
 *  this has no product_id (a single global row, not per-product) and why
 *  every field is manual (unlike DsPprEntry's own automated Total
 *  Orders/Total Leads/Catered Leads). */
class DsPprTiktokEntry extends Model
{
    // Explicit override — Eloquent's default snake_case pluralization of
    // "DsPprTiktokEntry" splits "DsPpr" into "ds_ppr" (not "dsppr"),
    // which doesn't match this app's own "dsppr_*" naming convention
    // (dsppr_entries, see DsPprEntry — that model gets away with the
    // default because "DsPprEntry" happens to only need one word split).
    protected $table = 'dsppr_tiktok_entries';

    protected $fillable = [
        'entry_date', 'gross_sales', 'net_income', 'ads_spent',
        'total_orders', 'total_leads', 'catered_leads',
        // Rate overrides (explicit request, 2026-10-07: "make it
        // editable") — nullable, null means "keep using
        // DsPprCalculator::derive()'s own formula" (see the migration's
        // own doc comment for why these aren't a formula replacement).
        'excess_leads_override', 'pickup_rate_override', 'conversion_rate_override', 'upselling_rate_override',
    ];

    protected $casts = [
        'entry_date'    => 'date',
        'gross_sales'   => 'float',
        'net_income'    => 'float',
        'ads_spent'     => 'float',
        'total_orders'  => 'integer',
        'total_leads'   => 'integer',
        'catered_leads' => 'integer',
        'excess_leads_override'     => 'integer',
        'pickup_rate_override'      => 'float',
        'conversion_rate_override'  => 'float',
        'upselling_rate_override'   => 'float',
    ];

    /** This row's own raw array, same 6-key shape DsPprCalculator::
     *  derive()/sum() expect, with any set override merged DIRECTLY onto
     *  the matching derived key (excess_leads/pickup_rate/conversion_rate/
     *  upselling_rate) — explicit request, 2026-10-07: "make it editable".
     *  A null override is simply omitted so derive()/sum() keep computing
     *  that key from the formula as normal (same "blank falls back to the
     *  formula" convention as Cost Breakdown's own overrides elsewhere).
     *  Percent overrides are stored as the same fraction convention as
     *  derive()'s own output (0.25 = 25%). */
    public function toRawRowWithOverrides(): array
    {
        $raw = $this->only(['gross_sales', 'net_income', 'ads_spent', 'total_orders', 'total_leads', 'catered_leads']);

        $overrideMap = [
            'excess_leads_override'    => 'excess_leads',
            'pickup_rate_override'     => 'pickup_rate',
            'conversion_rate_override' => 'conversion_rate',
            'upselling_rate_override'  => 'upselling_rate',
        ];
        foreach ($overrideMap as $overrideKey => $derivedKey) {
            if ($this->{$overrideKey} !== null) {
                $raw[$derivedKey] = $this->{$overrideKey};
            }
        }

        return $raw;
    }
}
