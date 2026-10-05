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
    ];

    protected $casts = [
        'entry_date'    => 'date',
        'gross_sales'   => 'float',
        'net_income'    => 'float',
        'ads_spent'     => 'float',
        'total_orders'  => 'integer',
        'total_leads'   => 'integer',
        'catered_leads' => 'integer',
    ];
}
